# Bedrock administration

Install `frolaxhq/bedrock-admin` alongside core. This package includes the Spatie access
adapter and adds the Blade console without requiring Inertia or Node. Set
`bedrock.administration.enabled` to `true`, prepare permission migrations and `HasRoles`
on the host's user model, then use `bedrock:admin-install` and `bedrock:admin-user`.
See the repository README for installation and upgrade instructions.
