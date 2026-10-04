fn main() {
    println!("Reference conditional fish-tier weights (not a cast simulation):");
    for (tier, weight) in game_rules::RARITY_TIERS
        .iter()
        .zip(game_rules::REFERENCE_TIER_WEIGHTS)
    {
        println!("{tier}: {weight}/1,000,000");
    }
}
