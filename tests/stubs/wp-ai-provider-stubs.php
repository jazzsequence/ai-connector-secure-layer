<?php
/**
 * Minimal stub AI provider for integration tests.
 *
 * Registers a fake 'anthropic' provider with the WP AI client registry so that
 * inject_lazy_auth() can set authentication on it and the test can retrieve it.
 * The real provider plugin is not required in the test environment.
 */

namespace AICSL\Tests\Stubs;

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

class StubProviderAvailability implements ProviderAvailabilityInterface {
	public function isConfigured(): bool {
		return true;
	}
}

class StubProviderModelDirectory implements ModelMetadataDirectoryInterface {
	public function listModelMetadata(): array {
		return [];
	}

	public function hasModelMetadata( string $modelId ): bool {
		return false;
	}

	public function getModelMetadata( string $modelId ): \WordPress\AiClient\Providers\Models\DTO\ModelMetadata {
		throw new \RuntimeException( 'Stub — no models defined.' );
	}
}

class StubAnthropicProvider implements ProviderInterface {
	public static function metadata(): ProviderMetadata {
		return new ProviderMetadata(
			'anthropic',
			'Anthropic (stub)',
			ProviderTypeEnum::from( 'cloud' )
		);
	}

	public static function model( string $modelId, ?ModelConfig $modelConfig = null ): ModelInterface {
		throw new \RuntimeException( 'Stub — model instantiation not supported.' );
	}

	public static function availability(): ProviderAvailabilityInterface {
		return new StubProviderAvailability();
	}

	public static function modelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new StubProviderModelDirectory();
	}
}
