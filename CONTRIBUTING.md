# Contributing

## Workflow (mandatory): feature -> stage -> main
1. Never commit or push directly to `main` or `stage`.
2. Branch from `stage`: `git switch stage && git pull && git switch -c feature/<short-name>` (also `fix/`, `chore/`, `docs/`).
3. Push the branch and open a PR into **`stage`**. Merge after both CI checks pass: **Lint and static checks** and **Database and PHPUnit**.
4. Test on `stage`. When it is verified, open a PR from **`stage` into `main`**. The **Only stage can merge into main** check rejects any other source branch.
5. Merge into `main` only after all checks pass.

## One-time setup after cloning
Enable the shared git hooks, which block direct pushes to `main` and `stage`:

    git config core.hooksPath .githooks

## Notes
- Never commit `.env` files or credentials; CI fails if one is tracked.
- GitHub branch protection is unavailable on the current plan, so this workflow is enforced by the local hook and review discipline.
