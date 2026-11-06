<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands\Concerns;

use Override;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait DeprecatesMakerAliases
{
    #[Override]
    public function run(InputInterface $input, OutputInterface $output): int
    {
        $invoked = $input->getFirstArgument();
        $canonical = $this->getName();

        if (is_string($invoked) && is_string($canonical) && in_array($invoked, $this->getAliases(), true)) {
            $output->writeln(sprintf('%s is deprecated; use %s instead.', $invoked, $canonical));
        }

        return parent::run($input, $output);
    }
}
