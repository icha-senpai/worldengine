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
    additional_origins: Vec<String>,
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
        let additional_origins = parse_additional_origins(
            &std::env::var("ACCOUNT_LINK_ALLOWED_ORIGINS").unwrap_or_default(),
        )?;
        Ok(Self {
            client_id: required("DISCORD_CLIENT_ID")?,
            client_secret: required("DISCORD_CLIENT_SECRET")?,
            redirect_uri,
            website_origin: website_url.origin().ascii_serialization(),
            additional_origins,
            website,
            secure_cookie: redirect.scheme() == "https",
            token_url: "https://discord.com/api/oauth2/token".into(),
            user_url: "https://discord.com/api/v10/users/@me".into(),
        })
    }

    fn return_website(&self, origin: Option<&str>) -> Option<String> {
        let origin = origin?;
        if origin == self.website_origin {
            Some(self.website.clone())
        } else if self
            .additional_origins
            .iter()
            .any(|allowed| allowed == origin)
        {
            Some(format!("{origin}/"))
        } else {
            None
        }
    }
}

fn parse_additional_origins(value: &str) -> Result<Vec<String>, Error> {
    let mut origins = Vec::new();
    for value in value
        .split(',')
        .map(str::trim)
        .filter(|value| !value.is_empty())
    {
        let url = Url::parse(value)?;
        let loopback = matches!(url.host_str(), Some("localhost" | "127.0.0.1" | "[::1]"));
        if (url.scheme() != "https" && !(url.scheme() == "http" && loopback))
            || url.host_str().is_none()
            || !url.username().is_empty()
            || url.password().is_some()
            || url.path() != "/"
            || url.query().is_some()
            || url.fragment().is_some()
        {
            return Err("ACCOUNT_LINK_ALLOWED_ORIGINS must contain plain HTTPS origins, except loopback development".into());
        }
        origins.push(url.origin().ascii_serialization());
    }
    Ok(origins)
}
struct Session {
    state: String,
    challenge_id: u64,
    proof: u128,
    expires: Instant,
    website: String,
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
    let Some(website) = state.config.return_website(
        headers
            .get(header::ORIGIN)
            .and_then(|value| value.to_str().ok()),
    ) else {
        return protected(
            (
                StatusCode::FORBIDDEN,
                "Open account linking from the Fishbound website.",
            )
                .into_response(),
        );
    };
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
                website,
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
    avatar: Option<String>,
    discriminator: Option<String>,
}
async fn verified_user(
    http: &reqwest::Client,
    config: &Config,
    code: &str,
) -> Result<DiscordUser, Error> {
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
    Ok(user)
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
            Ok(user) => match state
                .game
                .link(
                    session.challenge_id,
                    user.id.parse().expect("verified Discord ID"),
                    session.proof,
                )
                .await
            {
                Ok(()) => {
                    let discriminator = user
                        .discriminator
                        .as_deref()
                        .unwrap_or("0")
                        .parse()
                        .unwrap_or(0);
                    if let Err(error) = state
                        .game
                        .sync_portrait(
                            user.id.parse().expect("verified Discord ID"),
                            user.avatar,
                            discriminator,
                        )
                        .await
                    {
                        tracing::warn!(%error, "Verified Discord portrait could not refresh");
                    }
                    Redirect::to(&session.website).into_response()
                }
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
                website: "https://fishbound.test/".into(),
            },
        );
        assert!(take_session(&mut sessions, "other-cookie", "csrf").is_none());
        assert!(take_session(&mut sessions, "cookie", "wrong-state").is_none());
        let session = take_session(&mut sessions, "cookie", "csrf").unwrap();
        assert_eq!((session.challenge_id, session.proof), (1, 42));
        assert_eq!(session.website, "https://fishbound.test/");
        assert!(take_session(&mut sessions, "cookie", "csrf").is_none());
        sessions.insert(
            "expired".into(),
            Session {
                state: "csrf".into(),
                challenge_id: 1,
                proof: 42,
                expires: Instant::now() - Duration::from_secs(1),
                website: "https://fishbound.test/".into(),
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
                    Json(serde_json::json!({"id":"8001", "avatar":"a_1269e74af4df7417b13759eae50c83dc", "discriminator":"0"}))
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
            additional_origins: vec!["https://fishbound.test".into()],
            secure_cookie: false,
            token_url: format!("http://{address}/token"),
            user_url: format!("http://{address}/user"),
        };
        assert_eq!(
            config.return_website(Some("http://localhost")).as_deref(),
            Some("http://localhost")
        );
        assert_eq!(
            config
                .return_website(Some("https://fishbound.test"))
                .as_deref(),
            Some("https://fishbound.test/")
        );
        for origin in [
            None,
            Some("null"),
            Some("https://untrusted.test"),
            Some("https://fishbound.test.untrusted.test"),
            Some("https://fishbound.test:8443"),
        ] {
            assert!(config.return_website(origin).is_none());
        }
        let user = verified_user(&reqwest::Client::new(), &config, "proof-code")
            .await
            .unwrap();
        assert_eq!(user.id, "8001");
        assert_eq!(
            user.avatar.as_deref(),
            Some("a_1269e74af4df7417b13759eae50c83dc")
        );
        assert_eq!(user.discriminator.as_deref(), Some("0"));
        server.abort();
    }
    #[test]
    fn additional_origins_are_explicit_plain_origins() {
        assert!(parse_additional_origins("").unwrap().is_empty());
        assert_eq!(
            parse_additional_origins(" https://fishbound.test/, http://127.0.0.1:5174 ").unwrap(),
            vec!["https://fishbound.test", "http://127.0.0.1:5174"]
        );
        for value in [
            "*",
            "http://untrusted.test",
            "https://user@fishbound.test",
            "https://fishbound.test/redirect",
            "https://fishbound.test?redirect=evil",
            "https://fishbound.test#redirect",
        ] {
            assert!(parse_additional_origins(value).is_err(), "{value}");
        }
    }
}
