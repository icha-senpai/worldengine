use axum::{
    extract::{Form, Query, State as ExtractState},
    http::{HeaderMap, HeaderValue, StatusCode, header},
    response::{IntoResponse, Redirect, Response},
};
use game_client::{Client, Error};
use rand::Rng;
use serde::Deserialize;
use std::{
    collections::HashMap,
    sync::{Arc, Mutex},
    time::{Duration, Instant},
};
use url::Url;

pub fn required(name: &str) -> Result<String, Error> {
    std::env::var(name)
        .ok()
        .filter(|value| !value.trim().is_empty())
        .ok_or_else(|| format!("Set {name} in the service environment").into())
}
pub struct Config {
    client_id: String,
    client_secret: String,
    redirect_uri: String,
    website: String,
    website_origin: String,
    secure_cookie: bool,
    token_url: String,
    user_url: String,
}
impl Config {
    pub fn from_env() -> Result<Self, Error> {
        let website = required("WEBSITE_URL")?;
        let redirect_uri = required("DISCORD_REDIRECT_URI")?;
        let website_url = Url::parse(&website)?;
        let redirect = Url::parse(&redirect_uri)?;
        for url in [&website_url, &redirect] {
            let loopback = matches!(url.host_str(), Some("localhost" | "127.0.0.1" | "[::1]"));
            if url.scheme() != "https" && !(url.scheme() == "http" && loopback) {
                return Err(
                    "OAuth and website URLs require HTTPS, except loopback development".into(),
                );
            }
            if !url.username().is_empty()
                || url.password().is_some()
                || url.query().is_some()
                || url.fragment().is_some()
            {
                return Err(
                    "Use plain website and callback URLs without credentials or query strings"
                        .into(),
                );
            }
        }
        if redirect.path() != "/auth/discord/callback" {
            return Err("DISCORD_REDIRECT_URI must end in /auth/discord/callback".into());
        }
        Ok(Self {
            client_id: required("DISCORD_CLIENT_ID")?,
            client_secret: required("DISCORD_CLIENT_SECRET")?,
            redirect_uri,
            website_origin: website_url.origin().ascii_serialization(),
            website,
            secure_cookie: redirect.scheme() == "https",
            token_url: "https://discord.com/api/oauth2/token".into(),
            user_url: "https://discord.com/api/v10/users/@me".into(),
        })
    }
}
struct Session {
    state: String,
    challenge_id: u64,
    proof: u128,
    expires: Instant,
}
pub struct State {
    config: Config,
    http: reqwest::Client,
    sessions: Mutex<HashMap<String, Session>>,
    pub game: Arc<Client>,
}
impl State {
    pub fn new(config: Config, game: Arc<Client>) -> Result<Self, Error> {
        Ok(Self {
            config,
            game,
            sessions: Mutex::new(HashMap::new()),
            http: reqwest::Client::builder()
                .redirect(reqwest::redirect::Policy::none())
                .timeout(Duration::from_secs(10))
                .build()?,
        })
    }
}
#[derive(Deserialize)]
pub struct Start {
    challenge_id: u64,
    proof: String,
}
#[derive(Deserialize)]
pub struct Callback {
    state: String,
    code: Option<String>,
}

fn take_session(
    sessions: &mut HashMap<String, Session>,
    cookie: &str,
    state: &str,
) -> Option<Session> {
    if sessions
        .get(cookie)
        .is_some_and(|session| session.state == state && session.expires > Instant::now())
    {
        sessions.remove(cookie)
    } else {
        None
    }
}

fn session_cookie(headers: &HeaderMap) -> Option<&str> {
    headers
        .get(header::COOKIE)?
        .to_str()
        .ok()?
        .split(';')
        .find_map(|cookie| cookie.trim().strip_prefix("fishbound_oauth="))
}
fn cookie(value: &str, secure: bool, clear: bool) -> HeaderValue {
    HeaderValue::from_str(&format!(
        "fishbound_oauth={value}; Path=/auth/discord; Max-Age={}; HttpOnly; SameSite=Lax{}",
        if clear { 0 } else { 600 },
        if secure { "; Secure" } else { "" }
    ))
    .expect("generated cookie")
}
fn protected(mut response: Response) -> Response {
    response
        .headers_mut()
        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response.headers_mut().insert(
        header::REFERRER_POLICY,
        HeaderValue::from_static("no-referrer"),
    );
    response
}
pub async fn start(
    ExtractState(state): ExtractState<Arc<State>>,
    headers: HeaderMap,
    Form(input): Form<Start>,
) -> Response {
    if headers
        .get(header::ORIGIN)
        .and_then(|value| value.to_str().ok())
        != Some(&state.config.website_origin)
    {
        return protected(
            (
                StatusCode::FORBIDDEN,
                "Open account linking from the Fishbound website.",
            )
                .into_response(),
        );
    }
    let Ok(proof) = input.proof.parse::<u128>() else {
        return protected((StatusCode::BAD_REQUEST, "Invalid linking challenge.").into_response());
    };
    if input.challenge_id == 0 {
        return protected((StatusCode::BAD_REQUEST, "Invalid linking challenge.").into_response());
    }
    if !state.game.ready() {
        return protected(
            (
                StatusCode::SERVICE_UNAVAILABLE,
                "Account linking is temporarily unavailable.",
            )
                .into_response(),
        );
    }
    let session_id = format!("{:032x}", rand::thread_rng().r#gen::<u128>());
    let csrf = format!("{:032x}", rand::thread_rng().r#gen::<u128>());
    {
        let mut sessions = state.sessions.lock().expect("sessions mutex");
        sessions.retain(|_, session| session.expires > Instant::now());
        if sessions.len() >= 1024 {
            return protected(
                (
                    StatusCode::TOO_MANY_REQUESTS,
                    "Account linking is busy. Try again shortly.",
                )
                    .into_response(),
            );
        }
        if let Some(old) = session_cookie(&headers) {
            sessions.remove(old);
        }
        sessions.insert(
            session_id.clone(),
            Session {
                state: csrf.clone(),
                challenge_id: input.challenge_id,
                proof,
                expires: Instant::now() + Duration::from_secs(600),
            },
        );
    }
    let mut authorize = Url::parse("https://discord.com/oauth2/authorize").expect("Discord URL");
    authorize.query_pairs_mut().extend_pairs([
        ("client_id", state.config.client_id.as_str()),
        ("redirect_uri", &state.config.redirect_uri),
        ("response_type", "code"),
        ("scope", "identify"),
        ("state", &csrf),
        ("prompt", "consent"),
    ]);
    let mut response = Redirect::to(authorize.as_str()).into_response();
    response.headers_mut().insert(
        header::SET_COOKIE,
        cookie(&session_id, state.config.secure_cookie, false),
    );
    protected(response)
}

#[derive(Deserialize)]
struct AccessToken {
    access_token: String,
    token_type: String,
}
#[derive(Deserialize)]
struct DiscordUser {
    id: String,
}
async fn verified_user(http: &reqwest::Client, config: &Config, code: &str) -> Result<u64, Error> {
    let token: AccessToken = http
        .post(&config.token_url)
        .form(&[
            ("client_id", config.client_id.as_str()),
            ("client_secret", config.client_secret.as_str()),
            ("grant_type", "authorization_code"),
            ("code", code),
            ("redirect_uri", &config.redirect_uri),
        ])
        .send()
        .await?
        .error_for_status()?
        .json()
        .await?;
    if !token.token_type.eq_ignore_ascii_case("bearer") || token.access_token.is_empty() {
        return Err("Unexpected OAuth token type".into());
    }
    let user: DiscordUser = http
        .get(&config.user_url)
        .bearer_auth(&token.access_token)
        .send()
        .await?
        .error_for_status()?
        .json()
        .await?;
    let id = user.id.parse::<u64>()?;
    if id == 0 {
        return Err("Invalid verified Discord user".into());
    }
    Ok(id)
}
pub async fn callback(
    ExtractState(state): ExtractState<Arc<State>>,
    headers: HeaderMap,
    Query(input): Query<Callback>,
) -> Response {
    let session = {
        let mut sessions = state.sessions.lock().expect("sessions mutex");
        let key = session_cookie(&headers).unwrap_or_default();
        take_session(&mut sessions, key, &input.state)
    };
    let Some(session) = session else {
        return protected(
            (
                StatusCode::FORBIDDEN,
                "Linking session expired or did not match. Start again from Fishbound.",
            )
                .into_response(),
        );
    };
    let mut response = if let Some(code) = input
        .code
        .filter(|code| !code.is_empty() && code.len() <= 2048)
    {
        match verified_user(&state.http, &state.config, &code).await {
            Ok(discord_id) => match state
                .game
                .link(session.challenge_id, discord_id, session.proof)
                .await
            {
                Ok(()) => Redirect::to(&state.config.website).into_response(),
                Err(_) => {
                    tracing::warn!("Verified account challenge could not be linked");
                    (StatusCode::CONFLICT, "This linking challenge expired, was used, or belongs to a different account. Start again from Fishbound.").into_response()
                }
            },
            Err(_) => {
                tracing::warn!("Discord OAuth verification failed");
                (
                    StatusCode::BAD_GATEWAY,
                    "Discord login could not be verified. Start again from Fishbound.",
                )
                    .into_response()
            }
        }
    } else {
        (
            StatusCode::BAD_REQUEST,
            "Discord login was cancelled. Return to Fishbound to try again.",
        )
            .into_response()
    };
    response.headers_mut().insert(
        header::SET_COOKIE,
        cookie("", state.config.secure_cookie, true),
    );
    protected(response)
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn callbacks_require_matching_cookie_state_and_unexpired_single_use_session() {
        let mut sessions = HashMap::new();
        sessions.insert(
            "cookie".into(),
            Session {
                state: "csrf".into(),
                challenge_id: 1,
                proof: 42,
                expires: Instant::now() + Duration::from_secs(60),
            },
        );
        assert!(take_session(&mut sessions, "other-cookie", "csrf").is_none());
        assert!(take_session(&mut sessions, "cookie", "wrong-state").is_none());
        let session = take_session(&mut sessions, "cookie", "csrf").unwrap();
        assert_eq!((session.challenge_id, session.proof), (1, 42));
        assert!(take_session(&mut sessions, "cookie", "csrf").is_none());
        sessions.insert(
            "expired".into(),
            Session {
                state: "csrf".into(),
                challenge_id: 1,
                proof: 42,
                expires: Instant::now() - Duration::from_secs(1),
            },
        );
        assert!(take_session(&mut sessions, "expired", "csrf").is_none());
    }
    #[test]
    fn cookie_is_http_only_scoped_and_secure_in_production() {
        let value = cookie("abc", true, false).to_str().unwrap().to_owned();
        assert!(value.contains("HttpOnly; SameSite=Lax; Secure"));
        assert!(value.contains("Path=/auth/discord; Max-Age=600"));
        assert!(
            cookie("", true, true)
                .to_str()
                .unwrap()
                .contains("Max-Age=0")
        );
        let mut headers = HeaderMap::new();
        headers.insert(
            header::COOKIE,
            HeaderValue::from_static("other=x; fishbound_oauth=abc; next=y"),
        );
        assert_eq!(session_cookie(&headers), Some("abc"));
    }
    #[tokio::test]
    async fn oauth_exchanges_form_code_then_fetches_authenticated_identity() {
        use axum::{
            Json, Router,
            routing::{get, post},
        };
        let app = Router::new()
            .route(
                "/token",
                post(|Form(form): Form<HashMap<String, String>>| async move {
                    assert_eq!(
                        form.get("grant_type").map(String::as_str),
                        Some("authorization_code")
                    );
                    assert_eq!(form.get("code").map(String::as_str), Some("proof-code"));
                    assert_eq!(
                        form.get("client_secret").map(String::as_str),
                        Some("test-secret")
                    );
                    Json(serde_json::json!({"access_token":"test-access", "token_type":"Bearer"}))
                }),
            )
            .route(
                "/user",
                get(|headers: HeaderMap| async move {
                    assert_eq!(
                        headers.get(header::AUTHORIZATION).unwrap(),
                        "Bearer test-access"
                    );
                    Json(serde_json::json!({"id":"8001"}))
                }),
            );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let address = listener.local_addr().unwrap();
        let server = tokio::spawn(async move {
            axum::serve(listener, app).await.unwrap();
        });
        let config = Config {
            client_id: "test-client".into(),
            client_secret: "test-secret".into(),
            redirect_uri: "http://localhost/auth/discord/callback".into(),
            website: "http://localhost".into(),
            website_origin: "http://localhost".into(),
            secure_cookie: false,
            token_url: format!("http://{address}/token"),
            user_url: format!("http://{address}/user"),
        };
        assert_eq!(
            verified_user(&reqwest::Client::new(), &config, "proof-code")
                .await
                .unwrap(),
            8001
        );
        server.abort();
    }
}
