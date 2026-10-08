//! Preview the exact material attachments without Discord or game credentials.
use discord_bot::catch_art::{ArtError, Material, render_material_png};
use std::path::PathBuf;

fn main() -> Result<(), ArtError> {
    let root = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../..");
    let output = root.join("output/material-cards");
    std::fs::create_dir_all(&output)?;
    for material in [Material::RustedTin, Material::Scrap] {
        let png = render_material_png(&root.join("assets"), material)?;
        let path = output.join(format!("{}.png", material.key()));
        std::fs::write(&path, &png)?;
        println!("{}: {} bytes", path.display(), png.len());
    }
    Ok(())
}
