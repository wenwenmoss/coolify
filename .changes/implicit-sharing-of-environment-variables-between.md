---
type: fixed
breaking: true
---
Stopped implicit sharing of environment variables between Compose containers ([#7655](https://github.com/coollabsio/coolify/issues/7655))

- Declare runtime variables in each service environment section or explicitly opt into an env_file; the project .env remains available for Compose interpolation
- Build arguments and interpolation source variables are no longer added to container environments unless declared there; Coolify metadata is preserved
