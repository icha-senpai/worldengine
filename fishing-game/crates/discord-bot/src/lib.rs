pub mod achievement_notifications;
pub mod catch_art;
pub mod fish_again;
pub mod haul_again;
pub mod haul_notifications;
pub mod measurements;
pub mod perfect_catch;
pub mod profile_card;
pub mod raid_notifications;
pub mod sale_ids;

/// Shared with the isolated load harness so admission matches the live bot.
pub const MAX_PENDING_GAME_REQUESTS: usize = 32;
