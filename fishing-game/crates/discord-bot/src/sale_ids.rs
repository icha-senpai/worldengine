pub fn parse(text: &str) -> Result<Vec<u64>, &'static str> {
    let ids: Vec<u64> = text
        .split(|c: char| c == ',' || c.is_whitespace())
        .filter(|s| !s.is_empty())
        .map(|s| {
            s.strip_prefix('#')
                .unwrap_or(s)
                .parse::<u64>()
                .ok()
                .filter(|id| *id > 0)
                .ok_or("Use positive catch IDs separated by spaces or commas.")
        })
        .collect::<Result<_, _>>()?;
    if ids.is_empty() || ids.len() > 50 {
        return Err("Choose between 1 and 50 catch IDs.");
    }
    let mut sorted = ids.clone();
    sorted.sort_unstable();
    if sorted.windows(2).any(|pair| pair[0] == pair[1]) {
        return Err("A catch ID appears twice. Choose each catch once.");
    }
    Ok(ids)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn accepts_inventory_ids_without_silently_dropping_invalid_entries() {
        assert_eq!(parse("#12, 13 14").unwrap(), vec![12, 13, 14]);
        for input in ["", "12, x", "12,12", "0", "-1", "18446744073709551616"] {
            assert!(parse(input).is_err(), "{input}");
        }
        assert!(
            parse(
                &(1..=51)
                    .map(|id| id.to_string())
                    .collect::<Vec<_>>()
                    .join(",")
            )
            .is_err()
        );
    }
}
