//! Dockside Delivery: UTC days and a stamp card that survives missed days.
pub const DAY_MICROS: i64 = 86_400_000_000;
pub const BASE_COINS: u64 = 100;
pub const BONUS_COINS: u64 = 250;
pub const CARD_SIZE: u64 = 7;

pub fn utc_day(micros: i64) -> i64 {
    micros.div_euclid(DAY_MICROS)
}

pub fn next_reset(day: i64) -> Result<i64, String> {
    day.checked_add(1)
        .and_then(|day| day.checked_mul(DAY_MICROS))
        .ok_or_else(|| "DAILY_TIME_OVERFLOW".into())
}

pub fn award(claims: u64, last_day: Option<i64>, today: i64) -> Result<Option<(u64, u64)>, String> {
    if last_day.is_some_and(|day| day >= today) {
        return Ok(None);
    }
    let claims = claims.checked_add(1).ok_or("DAILY_COUNT_OVERFLOW")?;
    Ok(Some((
        claims,
        BASE_COINS
            + if claims.is_multiple_of(CARD_SIZE) {
                BONUS_COINS
            } else {
                0
            },
    )))
}

pub fn stamps(claims: u64) -> u8 {
    if claims == 0 {
        0
    } else {
        ((claims - 1) % CARD_SIZE + 1) as u8
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn utc_midnight_changes_eligibility() {
        assert_eq!(utc_day(DAY_MICROS - 1), 0);
        assert_eq!(utc_day(DAY_MICROS), 1);
        assert_eq!(next_reset(0), Ok(DAY_MICROS));
        assert_eq!(award(1, Some(0), 0), Ok(None));
        assert_eq!(award(1, Some(0), 1), Ok(Some((2, 100))));
        assert_eq!(award(1, Some(1), 0), Ok(None));
    }

    #[test]
    fn missed_days_preserve_stamps_and_every_seventh_pays_bonus() {
        let mut total = 0;
        for claim in 1..=14 {
            let day = claim as i64 * 3;
            let (count, coins) = award(claim - 1, Some(day - 3), day).unwrap().unwrap();
            assert_eq!(count, claim);
            assert_eq!(coins, if claim % 7 == 0 { 350 } else { 100 });
            assert_eq!(stamps(count), ((claim - 1) % 7 + 1) as u8);
            total += coins;
        }
        assert_eq!(total, 1900);
        assert_eq!(stamps(0), 0);
    }

    #[test]
    fn first_claim_and_overflows_are_explicit() {
        assert_eq!(award(0, None, 0), Ok(Some((1, 100))));
        assert_eq!(award(u64::MAX, None, 1), Err("DAILY_COUNT_OVERFLOW".into()));
        assert!(next_reset(i64::MAX).is_err());
    }
}
