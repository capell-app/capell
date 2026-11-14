<?php

declare(strict_types=1);

namespace Capell\Core\Events;

/**
 * Dispatched when `capell:doctor` finishes with every check passing. It carries
 * nothing about the installation: a listener that wants to report the milestone
 * decides for itself, under its own consent setting, what (if anything) to keep.
 */
final class CapellDoctorPassed {}
