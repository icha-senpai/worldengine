//! Measure the actual bot renderer without Discord or game credentials.
use discord_bot::catch_art::{ArtError, CatchArt, Material};
use std::{path::PathBuf, time::Instant};

#[tokio::main]
async fn main() -> Result<(), ArtError> {
    let root = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../..");
    let output = root.join("output/performance-cards");
    std::fs::create_dir_all(&output)?;
    let art = CatchArt::new(root.join("assets"));
    for material in [Material::RustedTin, Material::Scrap] {
        let started = Instant::now();
        let png = art.render_material(material).await?;
        let cold_ms = started.elapsed().as_secs_f64() * 1000.0;
        let started = Instant::now();
        let cached = art.render_material(material).await?;
        let cached_ms = started.elapsed().as_secs_f64() * 1000.0;
        assert_eq!(cached, png);
        std::fs::write(output.join(format!("{}.png", material.key())), &png)?;
        println!(
            "{}: {} bytes, cold {:.2} ms, cached {:.2} ms",
            material.key(),
            png.len(),
            cold_ms,
            cached_ms
        );
    }
    for (species, rank) in [("minnow", "F"), ("ancient-sturgeon", "UUR")] {
        let started = Instant::now();
        let png = art.render(species, rank).await?;
        let cold_ms = started.elapsed().as_secs_f64() * 1000.0;
        let started = Instant::now();
        let cached = art.render(species, rank).await?;
        let cached_ms = started.elapsed().as_secs_f64() * 1000.0;
        assert_eq!(cached, png);
        std::fs::write(output.join(format!("{species}-{rank}.png")), &png)?;
        println!(
            "{species}-{rank}: {} bytes, cold {:.2} ms, cached {:.2} ms",
            png.len(),
            cold_ms,
            cached_ms
        );
    }
    Ok(())
}
