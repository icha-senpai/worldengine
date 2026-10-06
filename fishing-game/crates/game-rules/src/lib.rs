//! Pure authoritative helpers. Production randomness comes from the module context.

pub mod daily;
pub mod measurements;
pub mod progression;
pub mod rods;
pub mod sampling;

#[cfg(test)]
mod world_tests;

pub const RARITY_TIERS: [&str; 10] = ["F", "D", "C", "B", "A", "S", "SS", "SSS", "UR", "UUR"];
pub const REFERENCE_TIER_WEIGHTS: [u32; 10] = [
    450_000, 270_000, 150_000, 75_000, 35_000, 14_000, 4_500, 1_200, 270, 30,
];

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum CatchCategory {
    Fish,
    Junk,
    Treasure,
}
