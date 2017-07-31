Redmine import for GitLab
=========================

PHP console application to import Redmine tickets of one or multiple projects into a GitLab issue tracker. Retains
original ticket numbers by **overwriting** existing GitLab issues and inserting **dummy** issues.

  * Tested with Redmine installation that used “GitRemote” VCS integration.
  * Dummy issues will be created as confidential.
  * Dummy issues will receive the label “import/skipped”.
  * **Does not** import more than 100 milestones (pagination not implemented when accessing GitLab API)
  * **Does not** import child issues/tasks as we do not use them at Fusonic.
  * **Does not** import watchers as they are not exposed/accessible using GitLab API.
  * **Does not** import issue relation types (e.g. “#1 _blocks_ #2”) as GitLab issue links do not support them.

## Usage

First, you need to create a config file containing access keys, assignee mapping, and mapping for Redmine ticket meta
data (tracker, status, priority, custom fields). See `app/config/sample.json`. A JSON schema can be found
`app/config/schema.json`. The config file MUST conform to that schema.

Then, run the import command:

```bash
$ bin/console do:import
```

If the import crashes (because GitLab is down or due to network problems, for example) you can perform a partial import
and skip already imported tickets using the `--first-ticket-number` option:

```bash
$ bin/console do:import --first-ticket-number=1337
```

## User mapping

Find your own user ID via the GitLab API while you are logged in to your GitLab account:

```
https://gitlab.com/api/v4/users?username=allgaeuer.fabian
```

Redmine assignees without a corresponding GitLab user ID are discarded. Issues will then be created without an assignee.

## Why another import tool?

This application has been used at [Fusonic](https://www.fusonic.net/) to import over 2600 tickets and nearly 40 versions
into an **existing** GitLab repository. We were using GitLab and Redmine parallel before we decided to fully move to
GitLab. As a result, new milestones were created in both GitLab (to set target versions on merge requests) **and** in
Redmine (to set target versions on tickets).

All existing import tools were not able to import data into an existing GitLab repository, and they require raw DB
access to retain original ticket numbers (which is impossible when using [gitlab.com](https://gitlab.com/)).
