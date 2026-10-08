//! Compose supplied fish and material sprites onto their Discord reward cards.
//! Original asset files are read only; PNG generation runs off the async executor.

use image::{ImageEncoder, RgbaImage, codecs::png::PngEncoder, imageops};
use std::{
    collections::VecDeque,
    io,
    path::{Path, PathBuf},
    sync::{Arc, Mutex},
    time::{Duration, Instant},
};

pub type ArtError = Box<dyn std::error::Error + Send + Sync>;

pub const ROD_QUALITY_COLORS: [u32; 7] = [
    0xa8a8a8, 0x69bf68, 0x6ba5ff, 0xbc80ee, 0xeaba5e, 0xe66699, 0x76ded9,
];

#[derive(Clone, Copy, Debug)]
pub enum Material {
    RustedTin,
    Scrap,
}

impl Material {
    pub fn key(self) -> &'static str {
        match self {
            Self::RustedTin => "rusted-tin",
            Self::Scrap => "scrap",
        }
    }
}

pub struct CatchArt {
    assets: PathBuf,
    workers: Arc<tokio::sync::Semaphore>,
    cache: Mutex<RenderCache>,
}

struct RenderCache {
    entries: VecDeque<(String, Vec<u8>)>,
    bytes: usize,
    max_bytes: usize,
    max_entries: usize,
}

impl RenderCache {
    fn new(max_bytes: usize, max_entries: usize) -> Self {
        Self {
            entries: VecDeque::new(),
            bytes: 0,
            max_bytes,
            max_entries,
        }
    }

    fn get(&mut self, key: &str) -> Option<Vec<u8>> {
        let index = self.entries.iter().position(|(name, _)| name == key)?;
        let entry = self.entries.remove(index)?;
        let png = entry.1.clone();
        self.entries.push_back(entry);
        Some(png)
    }

    fn insert(&mut self, key: String, png: Vec<u8>) {
        if png.len() > self.max_bytes || self.max_entries == 0 {
            return;
        }
        if let Some(index) = self.entries.iter().position(|(name, _)| *name == key) {
            self.bytes -= self
                .entries
                .remove(index)
                .expect("existing cache entry")
                .1
                .len();
        }
        while self.bytes + png.len() > self.max_bytes || self.entries.len() >= self.max_entries {
            self.bytes -= self.entries.pop_front().expect("full cache").1.len();
        }
        self.bytes += png.len();
        self.entries.push_back((key, png));
    }
}

impl CatchArt {
    pub fn new(assets: impl Into<PathBuf>) -> Self {
        Self {
            assets: assets.into(),
            workers: Arc::new(tokio::sync::Semaphore::new(2)),
            cache: Mutex::new(RenderCache::new(32 * 1024 * 1024, 64)),
        }
    }

    pub async fn render(&self, species_key: &str, rarity: &str) -> Result<Vec<u8>, ArtError> {
        let key = format!("fish:{species_key}:{rarity}");
        let species_key = species_key.to_owned();
        let rarity = rarity.to_owned();
        self.render_cached(key, move |assets| {
            encode_attachment(&fish_card(assets, &species_key, &rarity)?)
        })
        .await
    }

    pub async fn render_material(&self, material: Material) -> Result<Vec<u8>, ArtError> {
        self.render_cached(format!("material:{}", material.key()), move |assets| {
            encode_attachment(&material_card(assets, material)?)
        })
        .await
    }

    pub async fn render_rod(&self, rod_key: &str, quality_level: u8) -> Result<Vec<u8>, ArtError> {
        let key = format!("rod:{rod_key}:{quality_level}");
        let rod_key = rod_key.to_owned();
        self.render_cached(key, move |assets| {
            encode_attachment(&rod_card(assets, &rod_key, quality_level)?)
        })
        .await
    }

    async fn render_cached(
        &self,
        key: String,
        render: impl FnOnce(&Path) -> Result<Vec<u8>, ArtError> + Send + 'static,
    ) -> Result<Vec<u8>, ArtError> {
        let started = Instant::now();
        if let Some(png) = self.cache.lock().expect("art cache").get(&key) {
            tracing::info!(art = %key, cache_hit = true, bytes = png.len(), elapsed_ms = started.elapsed().as_millis() as u64, "Reward artwork timing");
            return Ok(png);
        }
        // Bound concurrent image memory/CPU work and the command's queue wait.
        // Holding the permit inside the worker also bounds work after cancellation.
        let permit =
            tokio::time::timeout(Duration::from_secs(5), self.workers.clone().acquire_owned())
                .await??;
        // A preceding worker may have completed this card while we queued.
        if let Some(png) = self.cache.lock().expect("art cache").get(&key) {
            tracing::info!(art = %key, cache_hit = true, bytes = png.len(), elapsed_ms = started.elapsed().as_millis() as u64, "Reward artwork timing");
            return Ok(png);
        }
        let queue_ms = started.elapsed().as_millis() as u64;
        let assets = self.assets.clone();
        let png = tokio::task::spawn_blocking(move || {
            let _permit = permit;
            render(&assets)
        })
        .await??;
        self.cache
            .lock()
            .expect("art cache")
            .insert(key.clone(), png.clone());
        tracing::info!(art = %key, cache_hit = false, bytes = png.len(), queue_ms, elapsed_ms = started.elapsed().as_millis() as u64, "Reward artwork timing");
        Ok(png)
    }
}

/// Render a standalone PNG, also used by the credential-free preview example.
pub fn render_png(assets: &Path, species_key: &str, rarity: &str) -> Result<Vec<u8>, ArtError> {
    encode_png(&fish_card(assets, species_key, rarity)?)
}

fn fish_card(assets: &Path, species_key: &str, rarity: &str) -> Result<RgbaImage, ArtError> {
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
    compose(card, &sprite)
}

/// Fixed material variants keep item paths independent of user input.
pub fn render_material_png(assets: &Path, material: Material) -> Result<Vec<u8>, ArtError> {
    encode_png(&material_card(assets, material)?)
}

fn material_card(assets: &Path, material: Material) -> Result<RgbaImage, ArtError> {
    let card = image::open(assets.join("item-cards/salvage.png"))?.to_rgba8();
    let sprite =
        image::open(assets.join("items").join(format!("{}.png", material.key())))?.to_rgba8();
    compose(card, &sprite)
}

fn rod_card(assets: &Path, rod_key: &str, quality_level: u8) -> Result<RgbaImage, ArtError> {
    if rod_key.is_empty()
        || !rod_key
            .bytes()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == b'-')
        || usize::from(quality_level) >= ROD_QUALITY_COLORS.len()
    {
        return Err(io::Error::new(
            io::ErrorKind::InvalidInput,
            "Invalid rod artwork key or quality",
        )
        .into());
    }
    let mut card = image::open(assets.join("item-cards/rod.png"))?.to_rgba8();
    let sprite = image::open(assets.join("rods").join(format!("{rod_key}.png")))?.to_rgba8();
    let bounds = visible_bounds(&sprite)?;
    let target = rod_placement(card.width(), card.height(), bounds);
    let crop =
        imageops::crop_imm(&sprite, bounds.x, bounds.y, bounds.width, bounds.height).to_image();
    let fitted = imageops::resize(
        &crop,
        target.width,
        target.height,
        imageops::FilterType::Nearest,
    );
    imageops::overlay(&mut card, &fitted, i64::from(target.x), i64::from(target.y));
    // Small quality-colored corner accents stay outside the rod's display box.
    let left = card.width() * 12 / 100;
    let right = card.width() * 88 / 100;
    let top = card.height() * 23 / 100;
    let bottom = card.height() * 87 / 100;
    let length = (card.width() * 3 / 100).max(1);
    let thickness = (card.width() / 256).max(1);
    for (index, (x, y, flip_x, flip_y)) in [
        (left, top, false, false),
        (right, top, true, false),
        (left, bottom, false, true),
        (right, bottom, true, true),
    ]
    .into_iter()
    .enumerate()
    {
        let color = if quality_level == 6 {
            [0xeaba5e, 0xe66699, 0x6ba5ff, 0x76ded9][index]
        } else {
            ROD_QUALITY_COLORS[usize::from(quality_level)]
        };
        let pixel = image::Rgba([(color >> 16) as u8, (color >> 8) as u8, color as u8, 255]);
        for offset in 0..length {
            for stroke in 0..thickness {
                let dx = if flip_x { x - offset } else { x + offset };
                let dy = if flip_y { y - offset } else { y + offset };
                card.put_pixel(dx, if flip_y { y - stroke } else { y + stroke }, pixel);
                card.put_pixel(if flip_x { x - stroke } else { x + stroke }, dy, pixel);
            }
        }
    }
    Ok(card)
}

fn rod_placement(card_width: u32, card_height: u32, bounds: Rect) -> Rect {
    let box_width = (card_width * 70 / 100).max(1);
    let box_height = (card_height * 58 / 100).max(1);
    let scale = (f64::from(box_width) / f64::from(bounds.width))
        .min(f64::from(box_height) / f64::from(bounds.height));
    let width = (f64::from(bounds.width) * scale).round() as u32;
    let height = (f64::from(bounds.height) * scale).round() as u32;
    Rect {
        x: (card_width - width) / 2,
        y: card_height * 26 / 100 + (box_height - height) / 2,
        width,
        height,
    }
}

fn encode_attachment(card: &RgbaImage) -> Result<Vec<u8>, ArtError> {
    // Discord displays these below their source resolution. A half-size PNG
    // keeps pixel edges crisp and preserves the original artwork on disk.
    encode_png(&imageops::resize(
        card,
        512,
        768,
        imageops::FilterType::Nearest,
    ))
}

fn encode_png(card: &RgbaImage) -> Result<Vec<u8>, ArtError> {
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
        return Err(
            io::Error::new(io::ErrorKind::InvalidData, "Sprite has no visible pixels").into(),
        );
    }
    Ok(Rect {
        x: left,
        y: top,
        width: right - left,
        height: bottom - top,
    })
}

fn placement(card_width: u32, card_height: u32, bounds: Rect) -> Rect {
    // Rank and salvage cards share this layout. At 1024 x 1536 the safe box is
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
        return Err(io::Error::new(io::ErrorKind::InvalidData, "Empty reward card").into());
    }
    let bounds = visible_bounds(sprite)?;
    let target = placement(card.width(), card.height(), bounds);
    let crop =
        imageops::crop_imm(sprite, bounds.x, bounds.y, bounds.width, bounds.height).to_image();
    // Nearest-neighbor keeps the supplied pixel artwork crisp when enlarged.
    let fitted = imageops::resize(
        &crop,
        target.width,
        target.height,
        imageops::FilterType::Nearest,
    );
    imageops::overlay(&mut card, &fitted, i64::from(target.x), i64::from(target.y));
    Ok(card)
}

#[cfg(test)]
mod tests {
    use super::*;
    use image::Rgba;

    #[test]
    fn cache_bounds_memory_and_entries_and_keeps_recently_used_cards() {
        let mut cache = RenderCache::new(6, 2);
        cache.insert("a".into(), vec![1; 3]);
        cache.insert("b".into(), vec![2; 3]);
        assert_eq!(cache.get("a"), Some(vec![1; 3]));
        cache.insert("c".into(), vec![3; 3]);
        assert!(cache.get("b").is_none());
        cache.insert("a".into(), vec![4]);
        assert_eq!(cache.get("a"), Some(vec![4]));
        assert_eq!(cache.bytes, 4);
        cache.insert("oversized".into(), vec![0; 7]);
        assert!(cache.get("oversized").is_none());
        cache.insert("d".into(), vec![5]);
        assert_eq!(cache.entries.len(), 2);
        assert!(cache.bytes <= cache.max_bytes);
        assert!(cache.get("c").is_none());
    }

    #[tokio::test]
    async fn attachments_preserve_scaled_art_and_cache_distinct_fish_ranks_and_materials() {
        let assets = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../assets");
        let art = CatchArt::new(&assets);
        let mut samples = Vec::new();
        for rank in ["F", "UUR"] {
            let png = art.render("minnow", rank).await.unwrap();
            let expected = imageops::resize(
                &fish_card(&assets, "minnow", rank).unwrap(),
                512,
                768,
                imageops::FilterType::Nearest,
            );
            assert_eq!(image::load_from_memory(&png).unwrap().to_rgba8(), expected);
            assert_eq!(art.render("minnow", rank).await.unwrap(), png);
            samples.push(png);
        }
        assert_ne!(samples[0], samples[1]);
        for material in [Material::RustedTin, Material::Scrap] {
            let png = art.render_material(material).await.unwrap();
            let expected = imageops::resize(
                &material_card(&assets, material).unwrap(),
                512,
                768,
                imageops::FilterType::Nearest,
            );
            assert_eq!(image::load_from_memory(&png).unwrap().to_rgba8(), expected);
            assert_eq!(art.render_material(material).await.unwrap(), png);
        }
        assert_eq!(art.cache.lock().unwrap().entries.len(), 4);
    }

    #[tokio::test]
    async fn failed_art_is_not_cached_and_worker_permits_are_released() {
        let art = CatchArt::new("unused");
        assert!(art.render("../minnow", "F").await.is_err());
        assert!(art.render_material(Material::Scrap).await.is_err());
        assert!(art.cache.lock().unwrap().entries.is_empty());
        assert_eq!(art.workers.available_permits(), 2);
    }

    #[tokio::test]
    async fn rods_fit_the_display_box_and_quality_cards_cache_separately() {
        let assets = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../assets");
        let background = image::open(assets.join("item-cards/rod.png"))
            .unwrap()
            .to_rgba8();
        assert_eq!(background.dimensions(), (1024, 1536));
        let scaled_background =
            imageops::resize(&background, 512, 768, imageops::FilterType::Nearest);
        let art = CatchArt::new(&assets);
        for key in [
            "twig", "river", "marsh", "moonwood", "tide", "glacial", "abyssal",
        ] {
            let sprite = image::open(assets.join("rods").join(format!("{key}.png")))
                .unwrap()
                .to_rgba8();
            let bounds = visible_bounds(&sprite).unwrap();
            let target = rod_placement(1024, 1536, bounds);
            assert!(
                target.x >= 154 && target.x + target.width <= 870,
                "{key} horizontal clipping"
            );
            assert!(
                target.y >= 399 && target.y + target.height <= 1290,
                "{key} vertical clipping"
            );
            assert!((2 * target.x + target.width).abs_diff(1024) <= 1);
            let scale = f64::from(target.width) / f64::from(bounds.width);
            assert!((f64::from(target.height) - f64::from(bounds.height) * scale).abs() <= 2.0);
        }
        let mut previous = None;
        for quality in 0..7 {
            let png = art.render_rod("twig", quality).await.unwrap();
            let card = image::load_from_memory(&png).unwrap().to_rgba8();
            assert_eq!(card.dimensions(), (512, 768));
            assert_eq!(
                card.get_pixel(256, 30),
                scaled_background.get_pixel(256, 30)
            );
            assert_eq!(art.render_rod("twig", quality).await.unwrap(), png);
            if let Some(previous) = previous {
                assert_ne!(png, previous);
            }
            previous = Some(png);
        }
        assert_eq!(art.cache.lock().unwrap().entries.len(), 7);
        assert!(art.render_rod("../twig", 0).await.is_err());
        assert!(art.render_rod("twig", 7).await.is_err());
        assert_eq!(art.cache.lock().unwrap().entries.len(), 7);
    }

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
        assert!(render_material_png(Path::new("unused"), Material::RustedTin).is_err());
        assert!(render_material_png(Path::new("unused"), Material::Scrap).is_err());
    }

    #[test]
    fn both_material_cards_fit_original_sprites_and_preserve_the_frame() {
        let assets = Path::new(env!("CARGO_MANIFEST_DIR")).join("../../assets");
        let background = image::open(assets.join("item-cards/salvage.png"))
            .unwrap()
            .to_rgba8();
        assert_eq!(background.dimensions(), (1024, 1536));
        for material in [Material::RustedTin, Material::Scrap] {
            let sprite = image::open(assets.join("items").join(format!("{}.png", material.key())))
                .unwrap()
                .to_rgba8();
            let target = placement(1024, 1536, visible_bounds(&sprite).unwrap());
            let png = render_material_png(&assets, material).unwrap();
            let card = image::load_from_memory(&png).unwrap().to_rgba8();
            assert_eq!(card.dimensions(), background.dimensions());
            let mut changed = 0;
            for (x, y, pixel) in card.enumerate_pixels() {
                if pixel != background.get_pixel(x, y) {
                    assert!(x >= target.x && x < target.x + target.width);
                    assert!(y >= target.y && y < target.y + target.height);
                    changed += 1;
                }
            }
            assert!(changed > 20_000, "{} is visibly rendered", material.key());
        }
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
