//! Preview the real public /rod attachments without Discord or game credentials.
use discord_bot::catch_art::{ArtError, CatchArt};
use std::path::PathBuf;

#[tokio::main]
async fn main() -> Result<(), ArtError> {
    let root = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../..");
    let output = root.join("output/rod-cards");
    std::fs::create_dir_all(&output)?;
    let art = CatchArt::new(root.join("assets"));
    for key in [
        "twig", "river", "marsh", "moonwood", "tide", "glacial", "abyssal",
    ] {
        let png = art.render_rod(key, 0).await?;
        std::fs::write(output.join(format!("{key}-common.png")), &png)?;
        println!("{key}: {} bytes", png.len());
    }
    for (level, name) in [
        "common",
        "uncommon",
        "rare",
        "epic",
        "legendary",
        "mythic",
        "prismatic",
    ]
    .iter()
    .enumerate()
    {
        let png = art.render_rod("twig", level as u8).await?;
        std::fs::write(output.join(format!("twig-{name}.png")), &png)?;
    }
    Ok(())
}
