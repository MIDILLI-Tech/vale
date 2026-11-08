# Contributing to Vale

First of all, thank you for considering contributing to Vale! Your help is highly appreciated and essential to making Vale a better product for everyone.

By contributing to this repository, you agree to license your contribution under the **GNU Affero General Public License v3.0 (AGPL-3.0)** and agree to the **Developer Certificate of Origin (DCO)**.

---

## Developer Certificate of Origin (DCO)

To ensure that all contributions are legally compliant and clearly licensed, Vale requires all contributors to agree to the **Developer Certificate of Origin (DCO)**.

### What does this mean?
By signing off on your commits, you certify that you wrote the code or have the right to submit it under the AGPL-3.0 license.

### How to Sign-Off on Your Commits

Signing off on your commits is easy! You just need to add a `Signed-off-by` line to your commit messages.

#### 1. Command Line Git
When committing your changes from the command line, simply add the `-s` or `--signoff` flag:

```bash
git commit -s -m "Your descriptive commit message"
```

This will automatically append the following line to the end of your commit message:

```text
Signed-off-by: Your Name <your.email@example.com>
```

*(Note: Your Name and email must match your Git configuration: `git config user.name` and `git config user.email`)*

#### 2. Amending an Unsigned Commit
If you forgot to sign off on your most recent commit, you can fix it before pushing using:

```bash
git commit --amend --signoff
```

#### 3. Fixing Multiple Unsigned Commits
If you have multiple unsigned commits in your branch, you can perform an interactive rebase to sign them off:

```bash
git rebase --interactive --signoff origin/master
```

---

## Code of Conduct & Contribution Flow

1. **Fork the Repository:** Create your own fork and clone it locally.
2. **Create a Branch:** Create a branch for your feature or bug fix (`git checkout -b feature/amazing-feature` or `git checkout -b fix/annoying-bug`).
3. **Write and Test Your Code:** Ensure your code is thoroughly tested and adheres to Vale's coding standards.
4. **Follow Code Quality Standards:**
   - Vale uses `PHP-CS-Fixer` and `PHP CodeSniffer` to maintain style consistency.
   - Run code formatting and style checks before committing:
     ```bash
     composer fix
     composer cs-fix
     composer lint
     composer cs
     ```
5. **Commit Your Changes:** Remember to sign off using `git commit -s`.
6. **Push and Submit a Pull Request:** Push your branch to your fork and submit a PR to the main Vale repository.
   - Fill out the PR template checklist to confirm your agreement to the DCO and license.
