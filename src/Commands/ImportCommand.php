<?php

declare(strict_types=1);

namespace Fusonic\GitLabImport\Commands;

use GuzzleHttp\Client;
use League\JsonGuard\Validator;
use League\JsonReference\Dereferencer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * @see https://docs.gitlab.com/ee/api/
 */
final class ImportCommand extends Command
{
    const OPTION_CONFIGURATION_FILENAME = "configuration-filename";
    const OPTION_FIRST_TICKET_NUMBER = "first-ticket-number";
    const OPTION_PROJECT_ID = "project-id";

    protected function configure()
    {
        $this
            ->setName("do:import")
            ->setDescription("Export issues of Redmine and import them into GitLab.")
            ->addOption(
                self::OPTION_CONFIGURATION_FILENAME,
                null,
                InputOption::VALUE_REQUIRED,
                "The configuration file to use for the import.",
                __DIR__ . "/../../app/config/sample.json"
            )
            ->addOption(
                self::OPTION_FIRST_TICKET_NUMBER,
                null,
                InputOption::VALUE_REQUIRED,
                "The first ticket number to import.",
                1
            )
            ->addOption(
                self::OPTION_PROJECT_ID,
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                "The numeric Redmine project IDs to import tickets from. Omit to import issues from all projects."
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $firstTicketNumberToImport = (int)$input->getOption(self::OPTION_FIRST_TICKET_NUMBER);
        $redmineProjectIds = $input->getOption(self::OPTION_PROJECT_ID);
        $configurationFilename = $input->getOption(self::OPTION_CONFIGURATION_FILENAME);
        $configuration = json_decode(file_get_contents($configurationFilename));

        // <editor-fold desc="Validate JSON.">

        $validator = new Validator(
            $configuration,
            Dereferencer::draft4()->dereference("file://" . __DIR__ . "/../../app/config/schema.json")
        );

        if ($validator->fails()) {
            $output->writeln(
                "<error>The specified configuration file “{$configurationFilename}” does not conform to the JSON " .
                "schema.</error>"
            );

            foreach ($validator->errors() as $error) {
                $output->writeln("<error>In {$error->getDataPath()}: {$error->getMessage()}</error>");
            }

            return 1;
        }

        // </editor-fold>

        $redmineBaseUri = $configuration->{'redmine'}->{'base-uri'};
        $gitLabBaseUri = $configuration->{'git-lab'}->{'base-uri'};

        if (!filter_var($redmineBaseUri, FILTER_VALIDATE_URL)) {
            $output->writeln("<error>The specified Redmine base URI “{$redmineBaseUri}” is not valid.</error>");
            return 1;
        }

        if (!filter_var($gitLabBaseUri, FILTER_VALIDATE_URL)) {
            $output->writeln("<error>The specified GitLab base URI “{$gitLabBaseUri}” is not valid.</error>");
            return 1;
        }

        $redmineHttpClient = new Client(
            [
                "auth" => [ $configuration->redmine->username, $configuration->redmine->password ],
                "base_uri" => rtrim($redmineBaseUri) . "/"
            ]
        );
        $gitLabHttpClient = new Client(
            [
                "headers" => [ "PRIVATE-TOKEN" => $configuration->{'git-lab'}->{'private-token'} ],
                "base_uri" => rtrim($gitLabBaseUri) . "/"
            ]
        );

        // <editor-fold desc="Determine total number of Redmine tickets.">

        $output->writeln(
            "<info>Fetching ticket number of most recent ticket from Redmine.\n" .
            "Please stand by.</info>"
        );

        $response = $redmineHttpClient->get("issues.json?status_id=*")
            ->getBody()
            ->getContents();

        $mostRecentTicketNumber = (int)json_decode($response, true)["issues"][0]["id"];

        if ($firstTicketNumberToImport > $mostRecentTicketNumber) {
            $output->writeln(
                "First ticket number of {$firstTicketNumberToImport} is greater than the total amount of tickets in " .
                "Redmine ({$mostRecentTicketNumber}). So, there is nothing to do."
            );
            return 0;
        }

        if (!$this->getHelper("question")->ask(
            $input,
            $output,
            new ConfirmationQuestion(
                "The most recent ticket number in Redmine is #{$mostRecentTicketNumber}.\nI will import all tickets, " .
                "starting from #{$firstTicketNumberToImport} up to #{$mostRecentTicketNumber}. Is that correct? [Yn] "
            )
        )) {
            return 0;
        }

        // </editor-fold>

        if (count($redmineProjectIds) == 0) {
            // Fetch all internal IDs of Redmine projects. No Redmine projects explicitly specified → import versions
            // and ticket of all Redmine projects.

            $response = $redmineHttpClient->get("projects.json")
                ->getBody()
                ->getContents();

            foreach (json_decode($response, true)["projects"] as $project) {
                $redmineProjectIds[] = $project["id"];
            }
        }

        // <editor-fold desc="Import Redmine versions.">

        $output->writeln(
            "<info>Fetching Redmine versions and compare with GitLab milestones.\n" .
            "Please stand by.</info>"
        );

        $gitLabMilestoneTitles = [ ];

        $response = $gitLabHttpClient->get("milestones?per_page=100")
            ->getBody()
            ->getContents();

        foreach (json_decode($response, true) as $milestone) {
            $gitLabMilestoneTitles[] = $milestone["title"];
        }

        foreach ($redmineProjectIds as $projectId) {
            $response = $redmineHttpClient->get("projects/{$projectId}/versions.json")
                ->getBody()
                ->getContents();

            foreach (json_decode($response, true)["versions"] as $version) {
                if (in_array($version["name"], $gitLabMilestoneTitles)) {
                    $output->writeln(
                        "<comment>Skipped import of version “{$version['name']}” as an identical GitLab milestone " .
                        "already exists.</comment>"
                    );
                    continue;
                }

                if (!isset($version["due_date"])) {
                    $requestData = [
                        "title" => $version["name"]
                    ];
                } else {
                    $requestData = [
                        "title" => $version["name"],
                        "due_date" => $version["due_date"]
                    ];
                }

                $gitLabHttpClient->post("milestones", [ "json" => $requestData ]);

                // Add new milestone to array. Otherwise we would try to re-create the same milestone for Redmine
                // sub-projects (versions are shared between project and sub-projects).
                $gitLabMilestoneTitles[] = $version["name"];

                $output->writeln("<info>Successfully imported milestone “{$version['name']}”.</info>");
            }
        }

        // </editor-fold>

        $gitLabMilestoneIds = [ ];

        $response = $gitLabHttpClient->get("milestones?per_page=100")
            ->getBody()
            ->getContents();

        foreach (json_decode($response, true) as $milestone) {
            // Re-fetch GitLab milestones after importing Redmine versions. The `$gitLabMilestoneIds` is used later on
            // to assign issues to the correct target version.

            $gitLabMilestoneIds[$milestone["title"]] = $milestone["id"];
        }

        $ticketStatusesThatWillCloseAnIssue = [ ];

        $response = $redmineHttpClient->get("issue_statuses.json")
            ->getBody()
            ->getContents();

        foreach (json_decode($response, true)["issue_statuses"] as $issueStatus) {
            if (isset($issueStatus["is_closed"])) {
                $ticketStatusesThatWillCloseAnIssue[] = $issueStatus["name"];
            }
        }

        // IMPORT ALL THE TICKETS AFTER PREPARATION.

        $gitLabLabelsMapping = $configuration->{'git-lab'}->{'labels-mapping'};
        $gitLabUsersMapping = $configuration->{'git-lab'}->{'users-mapping'};

        for ($i = $firstTicketNumberToImport; $i <= $mostRecentTicketNumber; $i++) {
            $output->writeln(
                "<info>Collecting data for Redmine ticket #{$i}.\n" .
                "Please stand by.</info>"
            );

            $redmineResponse = $redmineHttpClient->get(
                "issues/{$i}.json?include=attachments,relations,journals",
                [
                    "exceptions" => false
                ]
            );

            switch ($redmineResponse->getStatusCode()) {
                case 404:
                    $redmineTicketExists = false;
                    break;

                case 200:
                    $redmineTicketExists = true;
                    break;

                default:
                    throw new \RuntimeException(
                        "Unexpected status code received from Redmine for ticket #{$i}: " .
                        "{$redmineResponse->getStatusCode()}. Aborting."
                    );
            }

            $gitLabResponse = $gitLabHttpClient->get(
                "issues/{$i}",
                [
                    "exceptions" => false
                ]
            );

            switch ($gitLabResponse->getStatusCode()) {
                case 404:
                    $gitLabIssueExists = false;
                    break;

                case 200:
                    $gitLabIssueExists = true;
                    break;

                default:
                    throw new \RuntimeException(
                        "Unexpected status code received from GitLab for issue #{$i}: " .
                        "{$gitLabResponse->getStatusCode()}. Aborting."
                    );
            }

            if (!$redmineTicketExists) {
                $output->writeln(
                    "Skipped import of ticket #{$i} as it does not exist in Redmine.\nA (confidential) dummy issue " .
                    "will be created instead."
                );

                $isDummyIssue = true;
                $requestData = [
                    "confidential" => true,
                    "labels" => "import/skipped",
                    "title" => "Dummy issue created by Redmine import",
                    "description" =>
                        "Created by Redmine import to retain Redmine ticket numbers in GitLab. Original ticket does " .
                        "not exist in Redmine.",
                ];
            } else {
                $ticketData = json_decode($redmineResponse->getBody()->getContents(), true)["issue"];

                if (!in_array($ticketData["project"]["id"], $redmineProjectIds)) {
                    $output->writeln(
                        "Skipped import of ticket #{$i} as it does not belong to one of the specified Redmine " .
                        "projects.\nA (confidential) dummy issue will be created instead."
                    );

                    $isDummyIssue = true;
                    $requestData = [
                        "confidential" => true,
                        "labels" => "import/skipped",
                        "title" => "Dummy issue created by Redmine import",
                        "description" =>
                            "Created by Redmine import to retain Redmine ticket numbers in GitLab. The Redmine " .
                            "project the ticket belongs to was excluded from import.",
                    ];
                } else {
                    // <editor-fold desc="Upload attachments.">

                    $attachmentReplacementPairs = [ ];

                    foreach ($ticketData["attachments"] as $attachment) {
                        $output->writeln("Uploading attachment “{$attachment['filename']}”.");

                        $response = $gitLabHttpClient->post(
                            "uploads",
                            [
                                "multipart" => [
                                    [
                                        "name" => "file",
                                        "filename" => $attachment["filename"],
                                        "contents" => $redmineHttpClient->get($attachment["content_url"])
                                            ->getBody()
                                            ->getContents()
                                    ]
                                ]
                            ]
                        );

                        // Images in ticket descriptions (manually inserted by the author) are usually referenced using
                        // the 1st variant. Automated comments by Redmine will reference images using the 2nd variant.

                        $attachmentReferenceVariant1 = sprintf("![](%1\$s)", $attachment["filename"]);
                        $attachmentReferenceVariant2 = sprintf("![%1\$s](%1\$s)", $attachment["filename"]);

                        $markdown = json_decode($response->getBody()->getContents(), true)["markdown"];

                        $attachmentReplacementPairs[$attachmentReferenceVariant1] = $markdown;
                        $attachmentReplacementPairs[$attachmentReferenceVariant2] = $markdown;
                    }

                    // </editor-fold>

                    $isDummyIssue = false;
                    $requestData = [
                        "confidential" => isset($ticketData["is_private"]),
                        "title" => $ticketData["subject"],
                        "description" =>
                            "__Created/reported by: {$ticketData['author']['name']}__\n\n" .
                            strtr($ticketData["description"], $attachmentReplacementPairs)
                    ];

                    // <editor-fold desc="Assign milestone.">

                    if (isset($ticketData["fixed_version"])) {
                        $requestData["milestone_id"] = $gitLabMilestoneIds[$ticketData["fixed_version"]["name"]];
                    }

                    // </editor-fold>

                    // <editor-fold desc="Assignee.">

                    if (isset($ticketData["assigned_to"]) &&
                        isset($gitLabUsersMapping->{$ticketData["assigned_to"]["id"]})
                    ) {
                        $requestData["assignee_ids"] = [ (int)$gitLabUsersMapping->{$ticketData["assigned_to"]["id"]} ];
                    }

                    // </editor-fold>

                    // <editor-fold desc="Assign labels.">

                    $labels = [ ];

                    if (isset($gitLabLabelsMapping->tracker->{$ticketData["tracker"]["name"]})) {
                        $labels = array_merge(
                            $labels,
                            $gitLabLabelsMapping->tracker->{$ticketData["tracker"]["name"]}
                        );
                    }

                    if (isset($gitLabLabelsMapping->status->{$ticketData["status"]["name"]})) {
                        $labels = array_merge(
                            $labels,
                            $gitLabLabelsMapping->status->{$ticketData["status"]["name"]}
                        );
                    }

                    if (isset($gitLabLabelsMapping->priority->{$ticketData["priority"]["name"]})) {
                        $labels = array_merge(
                            $labels,
                            $gitLabLabelsMapping->priority->{$ticketData["priority"]["name"]}
                        );
                    }

                    foreach ($ticketData["custom_fields"] as $customField) {
                        if (!isset($gitLabLabelsMapping->{'custom-field'}->{$customField["name"]})) {
                            continue;
                        }

                        foreach ($customField["value"] as $fieldValue) {
                            if (!isset($gitLabLabelsMapping->{'custom-field'}->{$customField["name"]}->{$fieldValue})) {
                                continue;
                            }

                            $labels = array_merge(
                                $labels,
                                $gitLabLabelsMapping->{'custom-field'}->{$customField["name"]}->{$fieldValue}
                            );
                        }
                    }

                    $requestData["labels"] = implode(",", $labels);

                    // </editor-fold>
                }
            }

            if ($gitLabIssueExists) {
                $output->writeln("Updating existing GitLab issue.");
                $gitLabHttpClient->put("issues/{$i}", [ "json" => $requestData ]);
            } else {
                $output->writeln("Creating new GitLab issue.");
                $gitLabHttpClient->post("issues", [ "json" => $requestData ]);
            }

            // Add comments and relations to issue.
            // Relations are currently not supported by GitLab.

            if (!$isDummyIssue) {
                foreach ($ticketData["journals"] as $ticketEvent) {
                    // TODO: The Redmine API does not expose whether it is a private issue note.

                    if (isset($ticketEvent["notes"]) && strlen($ticketEvent["notes"]) > 0) {
                        // Extract commit hash from automated comments added by VCS integration.
                        // Only supports Git.

                        if (strpos($ticketEvent["notes"], "commit:")) {
                            $commitHash = [ ];
                            preg_match("/(?:commit:.*\|)([a-z0-9]{40})/", $ticketEvent["notes"], $commitHash);

                            if (count($commitHash) > 0) {
                                $output->writeln("Extracted commit hash “{$commitHash[1]}” from ticket comment.");
                                $ticketEvent["notes"] = $commitHash[1];
                            }
                        }

                        $gitLabHttpClient->post(
                            "issues/{$i}/notes",
                            [
                                "json" => [
                                    "body" =>
                                        "__By {$ticketEvent['user']['name']}__: " .
                                        strtr($ticketEvent["notes"], $attachmentReplacementPairs)
                                ]
                            ]
                        );
                    }
                }

                if (isset($ticketData["relations"])) {
                    foreach ($ticketData["relations"] as $relation) {
                        $gitLabHttpClient->post(
                            "issues/{$i}/notes",
                            [
                                "json" => [
                                    "body" =>
                                        "#{$relation['issue_id']} {$relation['relation_type']} " .
                                        "#{$relation['issue_to_id']}"
                                ]
                            ]
                        );
                    }
                }
            }

            // Close the issue if the Redmine status is configured that way.
            // Always close dummy issues!

            if ($isDummyIssue || in_array($ticketData["status"]["name"], $ticketStatusesThatWillCloseAnIssue)) {
                $gitLabHttpClient->put(
                    "issues/{$i}",
                    [
                        "json" => [ "state_event" => "close" ]
                    ]
                );
            }

            $output->writeln("<info>Successfully imported ticket #{$i} into GitLab.</info>\n");
        }

        return 0;
    }
}
