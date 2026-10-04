//! Produce real Discord attachment previews without Discord/database credentials.
use discord_bot::catch_art::{ArtError, render_png};
use image::{Rgba, RgbaImage, imageops};
use std::path::PathBuf;

fn main() -> Result<(), ArtError> {
    let root = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../..");
    let assets = root.join("assets");
    let output = root.join("output/catch-cards");
    std::fs::create_dir_all(&output)?;
    let samples = [
        ("nidalees-lost-sock", "F"),
        ("minnow", "D"),
        ("seahorse", "C"),
        ("eel", "B"),
        ("sunfish", "A"),
        ("ancient-sturgeon", "S"),
        ("aurora-frostfin", "SS"),
        ("octopus", "SSS"),
        ("ancient-sturgeon", "UR"),
        ("fihs", "UUR"),
    ];
    let mut sheet = RgbaImage::from_pixel(1280, 768, Rgba([21, 26, 35, 255]));
    for (index, (key, rank)) in samples.into_iter().enumerate() {
        let started = std::time::Instant::now();
        let png = render_png(&assets, key, rank)?;
        let card = image::load_from_memory(&png)?.to_rgba8();
        let thumb = imageops::resize(&card, 256, 384, imageops::FilterType::Nearest);
        imageops::overlay(
            &mut sheet,
            &thumb,
            (index % 5 * 256) as i64,
            (index / 5 * 384) as i64,
        );
        std::fs::write(output.join(format!("{key}-{rank}.png")), &png)?;
        println!(
            "{key} / {rank}: {} bytes, {} ms",
            png.len(),
            started.elapsed().as_millis()
        );
    }
    sheet.save(output.join("all-ranks-preview.png"))?;
    println!("Previews: {}", output.display());
    Ok(())
}
