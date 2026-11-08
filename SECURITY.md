# Security policy

Please report vulnerabilities privately through
[GitHub security advisories](https://github.com/m4v3r4/Wordpress-Local-TTS/security/advisories/new),
not in public issues. Include the version, the steps to reproduce and the impact you expect.

Only the latest release receives fixes. You should get a first answer within a week.

Areas of particular interest: the REST routes under `/wp-json/local-tts/v1/` (all require the
`manage_options` capability), downloads into `wp-content/uploads/local-tts/`, the update check
against GitHub releases, and the integrity checks in the browser worker.
