<?php
declare(strict_types=1);

namespace Espo\Modules\ContactPortal\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\ORM\EntityManager;
use Espo\Modules\ContactPortal\Util\AttachmentSaver;
use Espo\Modules\ContactPortal\Util\ContactUtil;
use Espo\ORM\Entity;

/** A command to assist with inserting Sepheo NocoDb directory record attachments
 */
class InsertDirectoryAttachments implements Command
{
    public function __construct(
        private readonly AttachmentSaver $attachmentSaver,
        private readonly ContactUtil $contactUtil,
        private readonly EntityManager $entityManager,
    ) {}

    public function run(Params $params, IO $io): void
    {
        $csvFile = $params->getArgument(0) ?: 'php://stdin';
        $io->writeLine("Importing attachments from $csvFile");

        $headers = null;
        $fieldHandlers = [
            'Email' => [],
            'Avatar' => function ($entity, $header, $filename, $url): ?string {
                $id = $this->storeFromURL(
                    $entity,
                    $url,
                    'cAvatar',
                    $filename,
                    false,
                );
                if ($id === null) {
                    return "failed to store $filename from $url";
                }
                return null;
            },
            'CV' => function ($entity, $header, $filename, $url): ?string {
                $id = $this->storeFromURL(
                    $entity,
                    $url,
                    'cAttachment',
                    $filename,
                    true,
                );
                if ($id === null) {
                    return "failed to store $filename from $url";
                }
                return null;
            },
        ];
        $index = [];

        $ix = [];
        $row = 1;

        // Iterate over the CSV file
        $io->writeLine('iterating');
        $handle = fopen($csvFile, 'r');
        if ($handle === false) {
            $io->writeLine("couldn't open file $csvFile");
        } else {
            while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
                // Capture and index the headers, once
                if (!$headers) {
                    $headers = $data;
                    foreach ($fieldHandlers as $header => $handler) {
                        $index[$header] = array_search($header, $data);
                    }
                    continue;
                }
                $io->writeLine("$row, $data[0]");

                $email = $data[$index['Email']];
                $contact = $this->contactUtil->findContactByEmail($email);
                if ($contact) {
                    $io->writeLine(
                        "Found a contact for email $email, ID: " .
                            $contact->getId(),
                    );
                } else {
                    $io->writeLine(
                        "Can't find a contact matching email $email - skipping",
                    );
                    continue;
                }

                // Expect the value to have the form:
                //    <name>(<url>)
                // Where <name> is the filename, and <url> is where to get it.
                foreach ($fieldHandlers as $header => $handler) {
                    if (!$handler) {
                        continue;
                    }

                    $value = $data[$index[$header]];
                    // Note, this $bvalue can have brackets in both the filename
                    // and the URL!  So we try to split on the pattern
                    // "(https?:", although structly that could appear too! But
                    // less likely...
                    if (
                        preg_match('/^(.*)[(](https?:.*)[)]$/', $value, $match)
                    ) {
                        $filename = $match[1];
                        $url = $match[2];

                        // DEBUG
                        //$io->writeLine(
                        //    "handling attachment for $email ($header): '$value' => ('$filename', '$url')",
                        //);
                        $io->writeLine(
                            "Getting '$header' for '$email' from $url => '$filename'",
                        );
                        if (
                            $error = $handler(
                                $contact,
                                $header,
                                $filename,
                                $url,
                            )
                        ) {
                            $io->writeLine("error: $error");
                        }
                    } else {
                        $io->writeLine(
                            "skipping non-attachment value of $header (for $email): $value",
                        );
                    }
                }

                $row++;
            }
            fclose($handle);
        }
    }

    /** Store an attachment to an entity field, given an URL to download it from */
    public function storeFromURL(
        Entity $entity,
        string $url,
        string $field,
        string $name = null,
        bool $isParent,
    ): string {
        $type = 'application/octet-stream'; // default
        $contents = file_get_contents($url);
        if ($contents === false) {
            throw new Error("could not retrieve url $url: " . error_get_last());
        }
        $name ??= basename($url);

        $size = mb_strlen($contents, '8bit');

        // parse the headers we need
        foreach ($http_response_header as $header) {
            $header = strtolower($header);
            if (preg_match("/^(.*?)\s*:\s*(.*?)\s*$/", $header, $matches)) {
                switch ($matches[1]) {
                    case 'content-type':
                        $type = $matches[2];
                        break;

                    case 'content-disposition':
                        if ($name === null) {
                            $parts = explode(';', $matches[2]);
                            foreach ($parts as $p) {
                                if (stripos($p, 'filename') !== false) {
                                    $kv = parse_ini_string($p);
                                    $name = $kv['filename'];
                                }
                            }
                        }
                        break;
                }
            }
        }

        $this->attachmentSaver->pruneExisting($entity, $field);
        $attachment = $this->attachmentSaver->createAttachment(
            $name,
            $type,
            $size,
            $field,
            $contents,
            $entity,
            $isParent,
        );

        $this->entityManager->saveEntity($attachment);
        $this->entityManager->saveEntity($entity);

        return $attachment->getId();
    }
}
