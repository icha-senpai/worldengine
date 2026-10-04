//! Compose the supplied fish sprite onto the saved catch's rarity card.
//! Original asset files are read only; PNG generation runs off the async executor.

use image::{ImageEncoder, RgbaImage, codecs::png::PngEncoder, imageops};
use std::{io, path::Path, path::PathBuf, sync::Arc, time::Duration};

pub type ArtError = Box<dyn std::error::Error + Send + Sync>;

pub struct CatchArt {
    assets: PathBuf,
    workers: Arc<tokio::sync::Semaphore>,
}

impl CatchArt {
    pub fn new(assets: impl Into<PathBuf>) -> Self {
        Self {
            assets: assets.into(),
            workers: Arc::new(tokio::sync::Semaphore::new(2)),
        }
    }

    pub async fn render(&self, species_key: &str, rarity: &str) -> Result<Vec<u8>, ArtError> {
        // Bound concurrent image memory/CPU work and the command's queue wait.
        // Holding the permit inside the worker also bounds work after cancellation.
        let permit =
            tokio::time::timeout(Duration::from_secs(5), self.workers.clone().acquire_owned())
                .await??;
        let assets = self.assets.clone();
        let species_key = species_key.to_owned();
        let rarity = rarity.to_owned();
        tokio::task::spawn_blocking(move || {
            let _permit = permit;
            render_png(&assets, &species_key, &rarity)
        })
        .await?
    }
}

/// Render a standalone PNG, also used by the credential-free preview example.
pub fn render_png(assets: &Path, species_key: &str, rarity: &str) -> Result<Vec<u8>, ArtError> {
    if species_key.is_empty()
        || !species_key
            .bytes()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == b'-')
        || !game_rules::RARITY_TIERS.contains(&rarity)
    {
        return Err(
            io::Error::new(io::ErrorKind::InvalidInput, "Invalid catch artwork key").into(),
        );
    }
    let card = image::open(assets.join("rank-cards").join(format!("{rarity}.png")))?.to_rgba8();
    let sprite = image::open(assets.join("fish").join(format!("{species_key}.png")))?.to_rgba8();
    let card = compose(card, &sprite)?;
    let mut bytes = Vec::new();
    PngEncoder::new(&mut bytes).write_image(
        card.as_raw(),
        card.width(),
        card.height(),
        image::ExtendedColorType::Rgba8,
    )?;
    Ok(bytes)
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
struct Rect {
    x: u32,
    y: u32,
    width: u32,
    height: u32,
}

fn visible_bounds(sprite: &RgbaImage) -> Result<Rect, ArtError> {
    let (mut left, mut top) = sprite.dimensions();
    let (mut right, mut bottom) = (0, 0);
    for (x, y, pixel) in sprite.enumerate_pixels() {
        if pixel[3] != 0 {
            left = left.min(x);
            top = top.min(y);
            right = right.max(x + 1);
            bottom = bottom.max(y + 1);
        }
    }
    if right == 0 || bottom == 0 {
        return Err(io::Error::new(
            io::ErrorKind::InvalidData,
            "Fish sprite has no visible pixels",
        )
        .into());
    }
    Ok(Rect {
        x: left,
        y: top,
        width: right - left,
        height: bottom - top,
    })
}

fn placement(card_width: u32, card_height: u32, bounds: Rect) -> Rect {
    // All ten supplied cards share this layout. At 1024 x 1536 the safe box is
    // 716 x 522, centered at (512, 768), clear of the rank header and outer frame.
    let max_width = (card_width * 70 / 100).max(1);
    let max_height = (card_height * 34 / 100).max(1);
    let scale = (f64::from(max_width) / f64::from(bounds.width))
        .min(f64::from(max_height) / f64::from(bounds.height));
    let width = (f64::from(bounds.width) * scale).round() as u32;
    let height = (f64::from(bounds.height) * scale).round() as u32;
    let width = width.clamp(1, max_width);
    let height = height.clamp(1, max_height);
    Rect {
        x: (card_width - width) / 2,
        y: (card_height - height) / 2,
        width,
        height,
    }
}

fn compose(mut card: RgbaImage, sprite: &RgbaImage) -> Result<RgbaImage, ArtError> {
    if card.width() == 0 || card.height() == 0 {
        return Err(io::Error::new(io::ErrorKind::InvalidData, "Empty rarity card").into());
    }
    let bounds = visible_bounds(sprite)?;
    let target = placement(card.width(), card.height(), bounds);
    let crop =
        imageops::crop_imm(sprite, bounds.x, bounds.y, bounds.width, bounds.height).to_image();
    // Nearest-neighbor keeps the supplied pixel artwork crisp when enlarged.
    let fish = imageops::resize(
        &crop,
        target.width,
        target.height,
        imageops::FilterType::Nearest,
    );
    imageops::overlay(&mut card, &fish, i64::from(target.x), i64::from(target.y));
    Ok(card)
}

#[cfg(test)]
mod tests {
    use super::*;
    use image::Rgba;

    #[test]
    fn transparent_padding_does_not_shift_or_shrink_the_fish() {
        let mut sprite = RgbaImage::new(100, 80);
        for y in 50..60 {
            for x in 7..37 {
                sprite.put_pixel(x, y, Rgba([255, 0, 0, 255]));
            }
        }
        let card = compose(
            RgbaImage::from_pixel(1024, 1536, Rgba([0, 0, 255, 255])),
            &sprite,
        )
        .unwrap();
        let mut visible = card.clone();
        for pixel in visible.pixels_mut() {
            if pixel[0] == 0 {
                pixel[3] = 0;
            }
        }
        let bounds = visible_bounds(&visible).unwrap();
        assert_eq!(bounds.width, 716);
        assert_eq!(bounds.height, 239);
        assert!((2 * bounds.x + bounds.width).abs_diff(1024) <= 1);
        assert!((2 * bounds.y + bounds.height).abs_diff(1536) <= 1);
        assert_eq!(*card.get_pixel(512, 200), Rgba([0, 0, 255, 255]));
        assert_eq!(*card.get_pixel(30, 768), Rgba([0, 0, 255, 255]));
    }

    #[test]
    fn tall_art_and_partial_alpha_fit_without_clipping_or_stretching() {
        let sprite = RgbaImage::from_pixel(10, 40, Rgba([255, 0, 0, 128]));
        let card = compose(
            RgbaImage::from_pixel(1024, 1536, Rgba([0, 0, 255, 255])),
            &sprite,
        )
        .unwrap();
        let center = card.get_pixel(512, 768);
        assert!((i16::from(center[0]) - 128).abs() <= 1);
        assert!((i16::from(center[2]) - 127).abs() <= 1);
        assert!(center[3] >= 254); // Alpha blending rounds to eight-bit channels.
        let bounds = placement(1024, 1536, visible_bounds(&sprite).unwrap());
        assert_eq!((bounds.width, bounds.height), (131, 522));
        assert_eq!(*card.get_pixel(512, bounds.y - 1), Rgba([0, 0, 255, 255]));
        assert_eq!(*card.get_pixel(bounds.x - 1, 768), Rgba([0, 0, 255, 255]));
    }

    #[test]
    fn missing_art_fails_without_a_blank_card_or_path_escape() {
        assert!(compose(RgbaImage::new(1024, 1536), &RgbaImage::new(32, 32)).is_err());
        assert!(render_png(Path::new("unused"), "../minnow", "F").is_err());
        assert!(render_png(Path::new("unused"), "minnow", "../F").is_err());
        assert!(render_png(Path::new("unused"), "minnow", "F").is_err());
    }

    #[test]
    fn every_supplied_sprite_fits_every_rank_card() {
        let assets = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../assets");
        let sprites: Vec<_> = std::fs::read_dir(assets.join("fish"))
            .unwrap()
            .map(|file| file.unwrap().path())
            .filter(|path| path.extension().is_some_and(|ext| ext == "png"))
            .map(|path| (path.clone(), image::open(path).unwrap().to_rgba8()))
            .collect();
        assert_eq!(sprites.len(), 251);
        for rank in game_rules::RARITY_TIERS {
            let card = image::open(assets.join("rank-cards").join(format!("{rank}.png"))).unwrap();
            assert_eq!((card.width(), card.height()), (1024, 1536));
            for (path, sprite) in &sprites {
                let visible = visible_bounds(sprite).unwrap();
                let rect = placement(card.width(), card.height(), visible);
                assert!(
                    rect.x >= 154 && rect.x + rect.width <= 870,
                    "{} / {rank}",
                    path.display()
                );
                assert!(
                    rect.y >= 507 && rect.y + rect.height <= 1029,
                    "{} / {rank}",
                    path.display()
                );
                assert!((2 * rect.x + rect.width).abs_diff(1024) <= 1);
                assert!((2 * rect.y + rect.height).abs_diff(1536) <= 1);
                let scale = f64::from(rect.width) / f64::from(visible.width);
                assert!((f64::from(rect.height) - f64::from(visible.height) * scale).abs() <= 2.0);
            }
        }
    }
}
