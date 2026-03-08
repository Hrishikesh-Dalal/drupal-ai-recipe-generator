<?php

namespace Drupal\ai_recipe_tester\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatMessage;

/**
 * Provides a form to test AI Context Injection and YAML parsing.
 */
class RecipeTesterForm extends FormBase {

  /**
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Drupal\ai\AiProviderPluginManager
   */
  protected $aiProvider;

  /**
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $connection;

  /**
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Dependency Injection for ConfigFactory, the AI Provider, and DB connection.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    $ai_provider,
    Connection $connection,
    AccountProxyInterface $current_user,
    TimeInterface $time
  ) {
    $this->configFactory = $config_factory;
    $this->aiProvider = $ai_provider;
    $this->connection = $connection;
    $this->currentUser = $current_user;
    $this->time = $time;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('ai.provider'),
      $container->get('database'),
      $container->get('current_user'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_recipe_tester_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['prompt'] = [
      '#type' => 'textarea',
      '#title' => $this->t('User Request'),
      '#description' => $this->t('Example: Create a Blog Post content type with a body field.'),
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate Recipe YAML'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Extract YAML from an LLM response (fenced or unfenced), normalize, and trim.
   */
  private function extractYamlFromLlmOutput(string $llm_output): string {
    // Prefer content inside the first fenced block if present.
    if (preg_match('/```(?:yaml)?\s*(.*?)\s*```/is', $llm_output, $matches)) {
      $llm_output = $matches[1];
    }

    // Decode entities in case something upstream encoded output.
    $llm_output = html_entity_decode($llm_output, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize line endings and trim.
    $llm_output = str_replace(["\r\n", "\r"], "\n", $llm_output);
    return trim($llm_output);
  }

  /**
   * Whitelist + normalize the parsed YAML into the exact recipe structure.
   */
  private function canonicalizeRecipe(array $parsed): array {
    $recipe = [
      'name' => (string) ($parsed['name'] ?? ''),
      'description' => (string) ($parsed['description'] ?? ''),
      'type' => (string) ($parsed['type'] ?? 'drupal-recipe'),
      'config' => [
        'install' => [],
      ],
    ];

    if (isset($parsed['config']['install']) && is_array($parsed['config']['install'])) {
      $recipe['config']['install'] = array_values($parsed['config']['install']);
    }
    elseif (isset($parsed['install']) && is_array($parsed['install'])) {
      // Some models incorrectly put install at the top-level.
      $recipe['config']['install'] = array_values($parsed['install']);
    }

    if ($recipe['name'] === '') {
      throw new \RuntimeException('Missing required key: name');
    }

    return $recipe;
  }

  /**
   * Dump the canonical recipe array back to stable YAML.
   */
  private function dumpRecipeYaml(array $recipe): string {
    return Yaml::dump(
      $recipe,
      10,
      2,
      Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
    );
  }

  /**
   * Escape text for safe display inside a <pre> element.
   *
   * We intentionally do NOT escape quotes because they're safe in a text node
   * and escaping them makes YAML harder to read.
   */
  private function escapeForPre(string $text): string {
    return strtr($text, [
      '&' => '&amp;',
      '<' => '&lt;',
      '>' => '&gt;',
    ]);
  }

  /**
   * Save a run row into the custom table.
   */
  private function saveRun(array $fields): void {
    // Keep it best-effort: never break the UI if logging fails.
    try {
      $this->connection->insert('ai_recipe_tester_runs')
        ->fields($fields)
        ->execute();
    }
    catch (\Throwable $t) {
      // Intentionally swallow to avoid masking the real error.
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $user_prompt = (string) $form_state->getValue('prompt');

    $simulated_context = json_encode([
      'existing_fields' => ['node.body', 'node.field_image'],
    ]);

    $system_directives = "You are an expert Drupal 11 Starshot Recipe Generator. You must output ONLY mathematically valid YAML. The root structure must contain the keys: 'name', 'description', 'type', and 'config'. Under 'config', use the 'install' array. REUSE existing machine names from the SITE CONTEXT. DO NOT create duplicate configurations.\n\nSITE CONTEXT:\n" . $simulated_context;

    $provider_id = NULL;
    $model_id = NULL;
    $raw_llm_output = NULL;
    $extracted_yaml = NULL;
    $canonical_yaml = NULL;
    $canonical_json = NULL;
    $status = 'error';
    $error_message = NULL;

    try {
      $sets = $this->aiProvider->getDefaultProviderForOperationType('chat');
      $provider_id = (string) ($sets['provider_id'] ?? '');
      $model_id = (string) ($sets['model_id'] ?? '');

      $provider = $this->aiProvider->createInstance($provider_id);

      $messages = new ChatInput([
        new ChatMessage('system', $system_directives),
        new ChatMessage('user', $user_prompt),
      ]);

      $this->messenger()->addStatus('Sending request to LLM using model: ' . $model_id);

      $response = $provider->chat($messages, $model_id)->getNormalized();
      $raw_llm_output = $response->getText();

      // Show raw output (for debugging).
      $this->messenger()->addMessage('Raw LLM Output: <pre>' . $this->escapeForPre($raw_llm_output) . '</pre>');

      $extracted_yaml = $this->extractYamlFromLlmOutput($raw_llm_output);

      $parsed_array = Yaml::parse($extracted_yaml);
      if (!is_array($parsed_array)) {
        throw new \RuntimeException('Parsed YAML did not produce a mapping/array.');
      }

      $canonical_recipe = $this->canonicalizeRecipe($parsed_array);
      $canonical_yaml = $this->dumpRecipeYaml($canonical_recipe);
      $canonical_json = json_encode($canonical_recipe, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

      $status = 'ok';

      $this->messenger()->addStatus('✅ YAML VALIDATION PASSED! Recipe Name generated: ' . $canonical_recipe['name']);
      $this->messenger()->addMessage('Canonical Recipe YAML: <pre>' . $this->escapeForPre($canonical_yaml) . '</pre>');
    }
    catch (ParseException $e) {
      $status = 'yaml_parse_failed';
      $error_message = $e->getMessage();
      $this->messenger()->addError('❌ YAML PARSE FAILED: ' . $error_message);
    }
    catch (\Exception $e) {
      $status = 'exception';
      $error_message = $e->getMessage();
      $this->messenger()->addError('❌ ERROR: ' . $error_message);
    }
    finally {
      $this->saveRun([
        'uid' => (int) $this->currentUser->id(),
        'provider_id' => $provider_id,
        'model_id' => $model_id,
        'user_prompt' => $user_prompt,
        'raw_llm_output' => $raw_llm_output,
        'extracted_yaml' => $extracted_yaml,
        'canonical_yaml' => $canonical_yaml,
        'canonical_json' => $canonical_json,
        'status' => $status,
        'error_message' => $error_message,
        'created' => (int) $this->time->getRequestTime(),
      ]);
    }
  }

}