# Dev scripts guidance

This directory/file is created as part of the security cleanup branch `security/remove-secrets`.

Important:
- DO NOT run dev scripts directly on production systems.
- Many scripts under `tools/` contained hard-coded credentials. They were NOT removed from history by this commit.
- Before running any tool script, set the required environment variables (DB_HOST, DB_USER, DB_PASS, DB_NAME) or edit the script locally.

Quick commands to run locally (example):

# On Unix-like (export env vars for a session)
export DB_HOST=127.0.0.1
export DB_USER=root
export DB_PASS='your_local_password'
export DB_NAME=ghazali

# On Windows (PowerShell):
$env:DB_HOST = '127.0.0.1'
$env:DB_USER = 'root'
$env:DB_PASS = 'your_local_password'
$env:DB_NAME = 'ghazali'

If you want to permanently remove secrets from git history, see SECURITY_FIXES.md for instructions and coordinate with all contributors.
