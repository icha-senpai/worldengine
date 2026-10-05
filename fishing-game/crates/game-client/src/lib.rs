//! Shared transport for the bot and OAuth linker. Gameplay remains in the module.
#[allow(dead_code, unused_imports)]
pub mod module_bindings;

use module_bindings::*;
use spacetimedb_sdk::{DbContext, Table};
use std::{
    sync::{Arc, Mutex as StdMutex},
    time::Duration,
};
use tokio::sync::{Mutex, oneshot};

pub type Error = Box<dyn std::error::Error + Send + Sync>;
pub fn service_token(name: &str) -> Result<String, Error> {
    if let Some(value) = std::env::var(name)
        .ok()
        .filter(|value| !value.trim().is_empty())
    {
        return Ok(value);
    }
    let path =
        std::env::var(format!("{name}_FILE")).map_err(|_| format!("Set {name} or {name}_FILE"))?;
    let token = std::fs::read_to_string(path)?.trim().to_owned();
    if token.is_empty() {
        return Err("Service token file is empty".into());
    }
    Ok(token)
}
type Reply = Result<(), String>;

fn send_once(sender: &Arc<StdMutex<Option<oneshot::Sender<Reply>>>>, result: Reply) {
    if let Some(sender) = sender.lock().expect("reply mutex").take() {
        let _ = sender.send(result);
    }
}
async fn receive(receiver: oneshot::Receiver<Reply>) -> Result<(), Error> {
    tokio::time::timeout(Duration::from_secs(12), receiver).await???;
    Ok(())
}
fn complete<E: std::fmt::Display>(
    sender: oneshot::Sender<Reply>,
) -> impl FnOnce(&ReducerEventContext, Result<Result<(), String>, E>) + Send + 'static {
    move |_, result| {
        let _ = sender.send(result.map_err(|e| e.to_string()).and_then(|r| r));
    }
}

pub struct Client {
    connection: StdMutex<Arc<DbConnection>>,
    uri: String,
    database: String,
    token: String,
    role: ServiceRole,
    // The adapter view selects one player per identity. Serialize selection + read + mutation.
    selection: Mutex<()>,
}

pub struct Snapshot {
    pub player: Player,
    pub inventory: Vec<OwnedSpecimen>,
    pub collection: Vec<PlayerSpeciesProgress>,
    pub species: Vec<SpeciesDefinition>,
    pub biomes: Vec<BiomeDefinition>,
    pub rods: Vec<RodDefinition>,
    pub owned_rods: Vec<OwnedRod>,
    pub licences: Vec<OwnedBiomeLicence>,
    pub listings: Vec<ShopListing>,
    pub config: GameConfig,
    pub receipt: Option<CommandReceipt>,
}

impl Client {
    pub async fn connect(
        uri: &str,
        database: &str,
        token: String,
        role: ServiceRole,
    ) -> Result<Self, Error> {
        let connection = Self::open_connection(uri, database, token.clone(), role).await?;
        Ok(Self {
            connection: StdMutex::new(Arc::new(connection)),
            uri: uri.into(),
            database: database.into(),
            token,
            role,
            selection: Mutex::new(()),
        })
    }

    async fn open_connection(
        uri: &str,
        database: &str,
        token: String,
        role: ServiceRole,
    ) -> Result<DbConnection, Error> {
        let (sender, receiver) = oneshot::channel();
        let sender = Arc::new(StdMutex::new(Some(sender)));
        let connected = sender.clone();
        let errors = sender.clone();
        let connection = DbConnection::builder().with_uri(uri).with_database_name(database).with_token(Some(token))
            .on_connect(move |conn, _, _| {
                let applied = connected.clone();
                let error = connected.clone();
                let mut queries = vec!["SELECT * FROM my_service"];
                if role == ServiceRole::DiscordAdapter {
                    queries.extend(["SELECT * FROM adapter_player", "SELECT * FROM adapter_receipt", "SELECT * FROM adapter_daily_receipt", "SELECT * FROM adapter_inventory",
                        "SELECT * FROM adapter_rods", "SELECT * FROM adapter_licences", "SELECT * FROM adapter_shop_quote", "SELECT * FROM shop_listing", "SELECT * FROM adapter_collection", "SELECT * FROM species_definition", "SELECT * FROM biome_definition", "SELECT * FROM rod_definition", "SELECT * FROM game_config"]);
                }
                conn.subscription_builder().on_applied(move |ctx| {
                    let authorized = ctx.db.my_service().iter().any(|service| service.active && service.role == role);
                    send_once(&applied, if authorized { Ok(()) } else { Err("Service identity has no active matching role; grant it as deployment owner".into()) });
                }).on_error(move |_, err| send_once(&error, Err(err.to_string()))).subscribe(queries);
            })
            .on_connect_error(move |_, error| send_once(&errors, Err(error.to_string())))
            .on_disconnect(|_, error| tracing::warn!(?error, "Database connection closed"))
            .build()?;
        connection.run_threaded();
        if let Err(error) = receive(receiver).await {
            let _ = connection.disconnect();
            return Err(error);
        }
        Ok(connection)
    }

    fn connection(&self) -> Arc<DbConnection> {
        self.connection.lock().expect("connection mutex").clone()
    }
    pub fn disconnect(&self) {
        let _ = self.connection().disconnect();
    }
    async fn active_connection(&self) -> Result<Arc<DbConnection>, Error> {
        let existing = self.connection();
        if existing.is_active() {
            return Ok(existing);
        }
        let connection = Arc::new(
            Self::open_connection(&self.uri, &self.database, self.token.clone(), self.role).await?,
        );
        *self.connection.lock().expect("connection mutex") = connection.clone();
        let _ = existing.disconnect();
        Ok(connection)
    }
    pub async fn reconnect(&self) -> Result<(), Error> {
        let _guard = tokio::time::timeout(Duration::from_secs(8), self.selection.lock()).await?;
        self.active_connection().await?;
        Ok(())
    }

    pub fn start_reconnect_watch(self: &Arc<Self>) -> tokio::task::JoinHandle<()> {
        let client = self.clone();
        tokio::spawn(async move {
            let mut interval = tokio::time::interval(Duration::from_secs(5));
            loop {
                interval.tick().await;
                if !client.connection().is_active() && client.reconnect().await.is_err() {
                    tracing::warn!("Database reconnect unavailable; retrying shortly");
                }
            }
        })
    }

    pub fn ready(&self) -> bool {
        let connection = self.connection();
        connection.is_active()
            && connection
                .db
                .my_service()
                .iter()
                .any(|service| service.active && service.role == self.role)
    }

    pub async fn player(
        &self,
        discord_user_id: u64,
        display_name: String,
        interaction_id: u64,
        guild_id: Option<u64>,
        channel_id: u64,
        cast: bool,
    ) -> Result<Snapshot, Error> {
        let _guard = tokio::time::timeout(Duration::from_secs(8), self.selection.lock()).await?;
        for attempt in 0..2 {
            let connection = self.active_connection().await?;
            let result = Self::player_once(
                &connection,
                discord_user_id,
                display_name.clone(),
                interaction_id,
                guild_id,
                channel_id,
                cast,
            )
            .await;
            if result.is_ok() || connection.is_active() || attempt == 1 {
                return result;
            }
        }
        unreachable!("bounded transport retry")
    }

    pub async fn daily(
        &self,
        discord_user_id: u64,
        display_name: String,
        interaction_id: u64,
        guild_id: Option<u64>,
        channel_id: u64,
    ) -> Result<DailyReceipt, Error> {
        let _guard = tokio::time::timeout(Duration::from_secs(8), self.selection.lock()).await?;
        for attempt in 0..2 {
            let connection = self.active_connection().await?;
            let result = async {
                let snapshot = Self::player_once(
                    &connection,
                    discord_user_id,
                    display_name.clone(),
                    interaction_id,
                    guild_id,
                    channel_id,
                    false,
                )
                .await?;
                let (sender, receiver) = oneshot::channel();
                connection.reducers.daily_from_discord_then(
                    discord_user_id,
                    interaction_id,
                    guild_id,
                    channel_id,
                    complete(sender),
                )?;
                receive(receiver).await?;
                connection
                    .db
                    .adapter_daily_receipt()
                    .iter()
                    .find(|row| {
                        row.interaction_id == interaction_id
                            && row.player_id == snapshot.player.player_id
                    })
                    .ok_or_else(|| {
                        "Delivery status unavailable; retry with the same interaction ID".into()
                    })
            }
            .await;
            if result.is_ok() || connection.is_active() || attempt == 1 {
                return result;
            }
        }
        unreachable!("bounded transport retry")
    }

    /// Serialized per-account quote/commit; waiting for Discord buttons never holds selection.
    pub async fn shop_action(
        &self,
        discord_user_id: u64,
        display_name: String,
        interaction_id: u64,
        listing_id: Option<u32>,
        nonce: Option<u128>,
    ) -> Result<ShopQuote, Error> {
        let _guard = tokio::time::timeout(Duration::from_secs(8), self.selection.lock()).await?;
        for attempt in 0..2 {
            let connection = self.active_connection().await?;
            let result = async {
                Self::player_once(
                    &connection,
                    discord_user_id,
                    display_name.clone(),
                    interaction_id,
                    None,
                    1,
                    false,
                )
                .await?;
                let (sender, receiver) = oneshot::channel();
                if let Some(listing_id) = listing_id {
                    connection.reducers.prepare_shop_from_discord_then(
                        discord_user_id,
                        interaction_id,
                        listing_id,
                        complete(sender),
                    )?;
                } else if let Some(nonce) = nonce {
                    connection.reducers.commit_shop_from_discord_then(
                        discord_user_id,
                        interaction_id,
                        nonce,
                        complete(sender),
                    )?;
                } else {
                    return Err("Missing shop intent".into());
                }
                receive(receiver).await?;
                connection
                    .db
                    .adapter_shop_quote()
                    .iter()
                    .next()
                    .ok_or_else(|| "Shop confirmation unavailable".into())
            }
            .await;
            if result.is_ok() || connection.is_active() || attempt == 1 {
                return result;
            }
        }
        unreachable!("bounded transport retry")
    }

    pub async fn change_loadout(
        &self,
        discord_user_id: u64,
        display_name: String,
        interaction_id: u64,
        biome_id: Option<u32>,
        rod_id: Option<u32>,
    ) -> Result<Snapshot, Error> {
        let _guard = tokio::time::timeout(Duration::from_secs(8), self.selection.lock()).await?;
        let connection = self.active_connection().await?;
        Self::player_once(
            &connection,
            discord_user_id,
            display_name.clone(),
            interaction_id,
            None,
            1,
            false,
        )
        .await?;
        let (sender, receiver) = oneshot::channel();
        connection.reducers.change_loadout_from_discord_then(
            discord_user_id,
            interaction_id,
            biome_id,
            rod_id,
            complete(sender),
        )?;
        receive(receiver).await?;
        Self::player_once(
            &connection,
            discord_user_id,
            display_name,
            interaction_id,
            None,
            1,
            false,
        )
        .await
    }

    async fn player_once(
        connection: &DbConnection,
        discord_user_id: u64,
        display_name: String,
        interaction_id: u64,
        guild_id: Option<u64>,
        channel_id: u64,
        cast: bool,
    ) -> Result<Snapshot, Error> {
        let (sender, receiver) = oneshot::channel();
        connection.reducers.select_discord_player_then(
            discord_user_id,
            display_name,
            interaction_id,
            complete(sender),
        )?;
        receive(receiver).await?;
        // Replaying a retained receipt after reconnect retrieves the committed outcome.
        if cast {
            let (sender, receiver) = oneshot::channel();
            connection.reducers.fish_from_discord_then(
                discord_user_id,
                interaction_id,
                guild_id,
                channel_id,
                complete(sender),
            )?;
            receive(receiver).await?;
        }
        let player = connection
            .db
            .adapter_player()
            .iter()
            .find(|row| row.discord_user_id == discord_user_id)
            .ok_or("Player view unavailable")?;
        let receipt =
            connection.db.adapter_receipt().iter().find(|row| {
                row.interaction_id == interaction_id && row.player_id == player.player_id
            });
        if cast && receipt.is_none() {
            return Err("Cast status unavailable; retry with the same interaction ID".into());
        }
        Ok(Snapshot {
            player,
            receipt,
            inventory: connection.db.adapter_inventory().iter().collect(),
            collection: connection.db.adapter_collection().iter().collect(),
            species: connection.db.species_definition().iter().collect(),
            biomes: connection.db.biome_definition().iter().collect(),
            rods: connection.db.rod_definition().iter().collect(),
            owned_rods: connection.db.adapter_rods().iter().collect(),
            licences: connection.db.adapter_licences().iter().collect(),
            listings: connection.db.shop_listing().iter().collect(),
            config: connection
                .db
                .game_config()
                .iter()
                .max_by_key(|row| row.version)
                .ok_or("Rules unavailable")?,
        })
    }

    pub async fn link(
        &self,
        challenge_id: u64,
        discord_user_id: u64,
        proof: u128,
    ) -> Result<(), Error> {
        let _guard = tokio::time::timeout(Duration::from_secs(8), self.selection.lock()).await?;
        for attempt in 0..2 {
            let connection = self.active_connection().await?;
            let result = async {
                let (sender, receiver) = oneshot::channel();
                connection.reducers.complete_account_link_then(
                    challenge_id,
                    discord_user_id,
                    proof,
                    complete(sender),
                )?;
                receive(receiver).await
            }
            .await;
            if result.is_ok() || connection.is_active() || attempt == 1 {
                return result;
            }
        }
        unreachable!("bounded transport retry")
    }
}

impl Drop for Client {
    fn drop(&mut self) {
        self.disconnect();
    }
}
