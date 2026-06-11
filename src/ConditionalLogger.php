<?php

namespace CommunityStoreMainfreight;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class ConditionalLogger extends AbstractLogger {
	private const LEVELS = [
		LogLevel::DEBUG => 0,
		LogLevel::INFO => 1,
		LogLevel::NOTICE => 2,
		LogLevel::WARNING => 3,
		LogLevel::ERROR => 4,
		LogLevel::CRITICAL => 5,
		LogLevel::ALERT => 6,
		LogLevel::EMERGENCY => 7,
	];

	private LoggerInterface $inner;
	private bool $debugEnabled;

	public function __construct (LoggerInterface $inner, bool $debugEnabled) {
		$this->inner = $inner;
		$this->debugEnabled = $debugEnabled;
	}

	public function log ($level, $message, array $context = []): void {
		if (!$this->debugEnabled && (self::LEVELS[$level] ?? 0) < self::LEVELS[LogLevel::WARNING]) {
			return;
		}
		$this->inner->log($level, $message, $context);
	}
}