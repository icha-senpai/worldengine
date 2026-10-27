use std::{
    collections::{HashMap, VecDeque},
    net::IpAddr,
    time::{Duration, Instant},
};

#[derive(Clone, Copy, PartialEq, Eq, Hash)]
pub enum Application {
    Fishbound,
    Evergather,
}

#[derive(Default)]
pub struct LoginLimits {
    clients: HashMap<(Application, IpAddr), VecDeque<Instant>>,
    applications: HashMap<Application, VecDeque<Instant>>,
}

impl LoginLimits {
    pub fn allow(&mut self, application: Application, client: IpAddr, now: Instant) -> bool {
        let recent = |at: &Instant| now.duration_since(*at) < Duration::from_secs(60);
        self.clients.retain(|_, attempts| {
            attempts.retain(recent);
            !attempts.is_empty()
        });
        let global = self.applications.entry(application).or_default();
        global.retain(recent);
        if global.len() >= 120
            || self
                .clients
                .get(&(application, client))
                .is_some_and(|attempts| attempts.len() >= 8)
        {
            return false;
        }
        global.push_back(now);
        self.clients
            .entry((application, client))
            .or_default()
            .push_back(now);
        true
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test]
    fn client_limit_recovers_and_games_do_not_share_quota() {
        let mut limits = LoginLimits::default();
        let now = Instant::now();
        let ip = "192.0.2.1".parse().unwrap();
        for _ in 0..8 {
            assert!(limits.allow(Application::Fishbound, ip, now));
        }
        assert!(!limits.allow(Application::Fishbound, ip, now));
        assert!(limits.allow(Application::Evergather, ip, now));
        assert!(limits.allow(Application::Fishbound, ip, now + Duration::from_secs(60)));
    }
    #[test]
    fn rotating_clients_cannot_bypass_application_limit_or_grow_memory() {
        let mut limits = LoginLimits::default();
        let now = Instant::now();
        for i in 1..=120 {
            assert!(limits.allow(Application::Fishbound, IpAddr::from([192, 0, 2, i]), now));
        }
        for i in 1..=255 {
            assert!(!limits.allow(Application::Fishbound, IpAddr::from([198, 51, 100, i]), now));
        }
        assert_eq!(limits.clients.len(), 120);
        assert!(limits.allow(Application::Evergather, IpAddr::from([192, 0, 2, 1]), now));
        assert!(limits.allow(
            Application::Fishbound,
            IpAddr::from([192, 0, 2, 1]),
            now + Duration::from_secs(60)
        ));
        assert_eq!(limits.clients.len(), 1);
    }
}
