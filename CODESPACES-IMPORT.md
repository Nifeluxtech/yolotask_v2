# Import this package into a new GitHub repo with Codespaces

This package is meant for a **new private YOLOTASK V2 repository**. Do not extract it over the current V1 JavaScript repository. The package archive contains files at its root, so extract it from the root of the new repository.

## Recommended route: upload the ZIP to the new repository, then extract in Codespaces

1. Download `yolotask-v2-cpanel-package.zip` to your device.
2. Create a new **private, empty** GitHub repository. A clear name is `yolotask-v2`. Leave it empty; do not add a README, license, or `.gitignore` yet.
3. On GitHub, open the new repository and use **Add file → Upload files** to upload the ZIP. Commit that archive to a temporary branch named `setup/import-package` rather than `main`.
4. Open the repo's **Code → Codespaces** menu and create a Codespace from `setup/import-package`.
5. In the Codespace terminal, confirm you are at the repository root:

   ```sh
   pwd
   git status --short --branch
   ```

6. Extract the archive into the repository root. Replace the archive name below if you renamed it:

   ```sh
   unzip -n yolotask-v2-cpanel-package.zip -d .
   ```

   `-n` means existing files are not overwritten. This is an extra safeguard, but the recommended empty repository should not have conflicting files.

7. Review what changed before committing:

   ```sh
   git status --short
   find . -maxdepth 2 -type f | sort | head -200
   ```

8. Remove the uploaded archive from the repository after extraction:

   ```sh
   rm yolotask-v2-cpanel-package.zip
   ```

9. Commit the scaffold and push the branch:

   ```sh
   git add -A
   git commit -m "Add YOLOTASK V2 cPanel starter package"
   git push -u origin setup/import-package
   ```

10. Open a pull request from `setup/import-package` into `main`. Review the file list and `.gitignore`. Merge only after confirming there are no secrets or user data in the branch.

GitHub Codespaces supports using Git directly inside the Codespace, including branch creation, commits, pushes, and pull requests. [1]

## Alternative: copy the ZIP directly into an existing Codespace with GitHub CLI

Use this if the ZIP is already on a computer that has GitHub CLI installed and authenticated. The official `gh codespace cp` command copies a local file to a Codespace path prefixed by `remote:`. [2] [3]

On your local computer, run:

```sh
gh auth login
gh codespace list
gh codespace cp ./yolotask-v2-cpanel-package.zip remote:/workspaces/yolotask-v2/
```

Replace `yolotask-v2` with the actual Codespace workspace directory name. Then, in the Codespace terminal, run:

```sh
cd /workspaces/yolotask-v2
unzip -n yolotask-v2-cpanel-package.zip -d .
rm yolotask-v2-cpanel-package.zip
git status --short
```

If the Codespace has a different name or repository path, check with `gh codespace list` or use `gh codespace cp --help`. GitHub documents Codespaces file transfer with `gh codespace cp`; `-r` is required when copying directories rather than a single archive. [2] [3]

## Browser/desktop drag-and-drop option

If you are using the Codespaces browser editor on a computer, open its Explorer and drag the downloaded ZIP from your computer's file manager into the Codespace workspace. Visual Studio Code documents dragging files from the computer's File Explorer into a Codespace. Then use the same `unzip -n`, review, remove, and Git commit steps above. [4]

On Android, the browser/editor file picker and drag-and-drop behavior may be limited. The repository web-upload method above is usually the simpler phone workflow. Do not paste the archive contents into a code editor.

## Before deploying to cPanel

This archive is an initial **starter package**, not the full YOLOTASK product. Read `SCAFFOLD-STATUS.md`. Several feature and SQL files are intentional placeholders, and the app shell prominently labels itself as under development. Do not enable production payments or import live customer data.

Before any cPanel deployment, follow `docs/cpanel-setup.md`. Confirm the host supports the planned release symlink and document roots. The apex domain may be constrained to `public_html`; ask the host before assuming it can point directly to `apps/main/public`. Create private environment configuration outside webroots and configure the deployment target. Deploy to staging first.

## References

[1]: https://docs.github.com/en/codespaces/developing-in-a-codespace/using-source-control-in-your-codespace "Using source control in your codespace"
[2]: https://docs.github.com/en/codespaces/developing-in-a-codespace/using-github-codespaces-with-github-cli "Using GitHub Codespaces with GitHub CLI"
[3]: https://cli.github.com/manual/gh_codespace_cp "gh codespace cp"
[4]: https://code.visualstudio.com/docs/remote/codespaces "GitHub Codespaces in Visual Studio Code"
