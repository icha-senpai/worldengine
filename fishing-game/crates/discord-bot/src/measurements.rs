//! US customary presentation of authoritative integer millimeters and grams.
pub fn format_length(millimeters: u64) -> String {
    let tenths = (u128::from(millimeters) * 100 + 127) / 254;
    format!("{}.{:01} in", tenths / 10, tenths % 10)
}

pub fn format_weight(grams: u64) -> String {
    // One avoirdupois ounce is exactly 28.349523125 grams. Round before
    // splitting pounds so no card ever displays a 16-ounce remainder.
    let hundredths = (u128::from(grams) * 100_000_000_000 + 14_174_761_562) / 28_349_523_125;
    let pounds = hundredths / 1600;
    let remainder = hundredths % 1600;
    let ounces = format!("{}.{:02} oz", remainder / 100, remainder % 100);
    if pounds == 0 {
        ounces
    } else if remainder == 0 {
        format!("{pounds} lb")
    } else {
        format!("{pounds} lb {ounces}")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn lengths_use_inches_and_keep_one_decimal() {
        assert_eq!(format_length(127), "5.0 in");
        assert_eq!(format_length(254), "10.0 in");
        assert_eq!(format_length(44), "1.7 in");
    }

    #[test]
    fn weights_preserve_tiny_catches_and_split_at_the_pound_boundary() {
        assert_eq!(format_weight(1), "0.04 oz");
        assert_eq!(format_weight(453), "15.98 oz");
        assert_eq!(format_weight(454), "1 lb 0.01 oz");
        assert_eq!(format_weight(907), "1 lb 15.99 oz");
        assert_eq!(format_weight(908), "2 lb 0.03 oz");
        assert_eq!(format_weight(2268), "5 lb");
    }
}
