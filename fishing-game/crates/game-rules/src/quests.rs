//! UTC contract weeks begin on Monday; missed weeks have no streak penalty.
pub fn utc_week(day: i64) -> i64 {
    (day + 3).div_euclid(7)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn contracts_reset_on_monday_including_before_the_epoch() {
        assert_eq!(utc_week(0), utc_week(3)); // Epoch Thursday through Sunday.
        assert_eq!(utc_week(4), utc_week(3) + 1);
        assert_eq!(utc_week(10), utc_week(4));
        assert_eq!(utc_week(11), utc_week(4) + 1);
        assert_eq!(utc_week(-4), utc_week(-3) - 1);
        assert_eq!(utc_week(-3), utc_week(0));
    }
}
