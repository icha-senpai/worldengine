//! Credential-free review of the exact artwork sent with a perfect catch embed.
use discord_bot::catch_art::CatchArt;

#[tokio::main]
async fn main() -> Result<(), Box<dyn std::error::Error + Send + Sync>> {
    let assets = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../assets");
    let output = std::env::args()
        .nth(1)
        .unwrap_or_else(|| "output/balance/perfect-carp-card.png".into());
    std::fs::write(
        output,
        CatchArt::new(assets).render_perfect("carp", "UUR").await?,
    )?;
    Ok(())
}
