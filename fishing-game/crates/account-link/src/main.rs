mod oauth;
use axum::{
    Router,
    http::StatusCode,
    routing::{get, post},
};
use game_client::{Client, Error, module_bindings::ServiceRole};
use std::sync::Arc;

#[tokio::main]
async fn main() -> Result<(), Error> {
    dotenvy::dotenv().ok();
    tracing_subscriber::fmt()
        .with_env_filter(tracing_subscriber::EnvFilter::from_default_env())
        .init();
    let bind = std::env::var("ACCOUNT_LINK_BIND").unwrap_or_else(|_| "127.0.0.1:3001".into());
    let config = oauth::Config::from_env()?;
    let game = Arc::new(
        Client::connect(
            &oauth::required("SPACETIMEDB_URI")?,
            &oauth::required("SPACETIMEDB_DATABASE")?,
            game_client::service_token("SPACETIMEDB_LINKER_TOKEN")?,
            ServiceRole::AccountLinker,
        )
        .await?,
    );
    let _reconnect_watch = game.start_reconnect_watch();
    let state = oauth::State::new(config, game)?;
    let app = Router::new()
        .route("/health/live", get(|| async { "alive" }))
        .route(
            "/health/ready",
            get(
                |axum::extract::State(state): axum::extract::State<Arc<oauth::State>>| async move {
                    if state.game.ready() {
                        (StatusCode::OK, "ready")
                    } else {
                        (StatusCode::SERVICE_UNAVAILABLE, "database unavailable")
                    }
                },
            ),
        )
        .route("/auth/discord/start", post(oauth::start))
        .route("/auth/discord/callback", get(oauth::callback))
        .layer(axum::extract::DefaultBodyLimit::max(4096))
        .with_state(Arc::new(state));
    let listener = tokio::net::TcpListener::bind(&bind).await?;
    tracing::info!(%bind, "Account-link service ready");
    axum::serve(listener, app)
        .with_graceful_shutdown(async {
            tokio::signal::ctrl_c().await.ok();
        })
        .await?;
    Ok(())
}
