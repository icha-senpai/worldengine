//! Real bot transport proof. Restricted to a disposable Fishbound loopback database.
use game_client::{Client, module_bindings::ServiceRole};

#[tokio::main]
async fn main() -> Result<(), game_client::Error> {
    let database = std::env::var("FISHBOUND_PROOF_DATABASE")?;
    if !database.starts_with("fishbound-proof-timed-haul-")
        || !database["fishbound-proof-timed-haul-".len()..]
            .chars()
            .all(|c| c.is_ascii_digit())
    {
        return Err("Disposable timed-haul proof database required".into());
    }
    let token = std::env::var("FISHBOUND_PROOF_TOKEN")?;
    let client = Client::connect(
        "http://127.0.0.1:3127",
        &database,
        token,
        ServiceRole::DiscordAdapter,
    )
    .await?;
    let args: Vec<_> = std::env::args().skip(1).collect();
    let boat_id: u64 = args.get(1).ok_or("Boat interaction required")?.parse()?;
    let net_id: u64 = args.get(2).ok_or("Net interaction required")?.parse()?;
    let status_id: u64 = args.get(3).ok_or("Status interaction required")?.parse()?;
    if args.first().map(String::as_str) == Some("start") {
        let boat = client
            .start_haul(
                9462,
                "Native proof".into(),
                boat_id,
                None,
                42,
                "boat".into(),
            )
            .await?;
        let net = client
            .start_haul(9462, "Native proof".into(), net_id, None, 42, "net".into())
            .await?;
        assert_eq!(boat.interaction_id, boat_id);
        assert_eq!(net.interaction_id, net_id);
        assert_eq!(
            boat.due_at.to_micros_since_unix_epoch() - boat.started_at.to_micros_since_unix_epoch(),
            600_000_000
        );
        assert_eq!(
            net.due_at.to_micros_since_unix_epoch() - net.started_at.to_micros_since_unix_epoch(),
            240_000_000
        );
        let status = client
            .start_haul(
                9462,
                "Native proof".into(),
                status_id,
                None,
                42,
                "boat".into(),
            )
            .await?;
        assert_eq!(status.interaction_id, boat_id);
        client
            .player(9462, "Native proof".into(), status_id + 1, None, 42, true)
            .await?;
        println!(
            "PASS native bot transport: both starts, existing-trip status and normal fishing during timers."
        );
    } else {
        for (id, count) in [(boat_id, 10), (net_id, 4)] {
            assert!(
                client
                    .pending_hauls()
                    .iter()
                    .any(|trip| trip.interaction_id == id)
            );
            client.finish_haul(id).await?;
            let trip = client
                .claim_haul(id, u128::from(id))
                .await?
                .ok_or("Lease unavailable")?;
            assert_eq!(trip.results.len(), count);
            client.acknowledge_haul(id, u128::from(id)).await?;
        }
        let status = client
            .start_haul(
                9462,
                "Native proof".into(),
                status_id,
                None,
                42,
                "boat".into(),
            )
            .await?;
        assert_eq!(status.interaction_id, boat_id);
        assert_eq!(status.status, "delivered");
        println!(
            "PASS native bot transport: reconnect, due-trip recovery, result leases/ACK and completed status replay."
        );
    }
    Ok(())
}
