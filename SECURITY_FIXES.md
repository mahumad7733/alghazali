# Security fixes and recommended next steps

Applied in branch: security/remove-secrets (do not merge to main before review)

What I changed in this branch (non-destructive):
- Added .gitignore to prevent committing local env files and common logs.
- Redacted DB_PASS in `.env.example` to avoid shipping secrets in examples.
- Added `includes/missing_stubs.php` as temporary stubs to avoid fatal errors during initial testing. These are NOT final implementations.
- Added `scripts/dev/README.md` with guidance for safe usage of dev scripts.

Important follow-ups (manual or require approval):
1) Rotate all exposed credentials immediately (DB passwords mentioned in previous commits/files). Treat them as compromised if the repository was public.
2) If you want to remove secrets completely from git history, use `git filter-repo` or BFG and coordinate a forced push. Example steps are provided below — DO NOT run them until you understand the impact.

Example history-cleaning (owner approval required):
- Using git-filter-repo:
  git clone --mirror git@github.com:mahumad7733/alghazali.git
  cd alghazali.git
  git filter-repo --path .env --invert-paths
  # or to replace secrets with [REDACTED]
  # prepare a replacements.txt and run git filter-repo --replace-text replacements.txt
  git push --force

- Using BFG:
  bfg --delete-files ".env"
  git reflog expire --expire=now --all
  git gc --prune=now --aggressive
  git push --force

Checklist before merging to Developer/main:
- [ ] All secrets rotated.
- [ ] DB migration scripts reviewed by DBA and converted to ordered migrations.
- [ ] Dev-only scripts moved to an internal repo or protected by env guards.
- [ ] Missing function implementations added or properly imported.
- [ ] Static checks/lint and unit tests pass.

If you want me to continue and:
- Replace hard-coded credentials programmatically inside `tools/*` files, add env guards, and create dedicated migrations, reply here and I will proceed with those code edits on the branch.
