# Bedrock Spatie access adapter

Install `frolaxhq/bedrock-access-spatie` to mirror core module permission declarations into
Spatie permissions and manage roles/grants for the configured guard. Core permission
declarations work independently of this package. The adapter preserves the host's User
model and emits successful access-operation events for optional audit capture.
