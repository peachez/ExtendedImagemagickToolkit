<?php

declare(strict_types=1);

namespace Drupal\extended_imagemagick\Plugin\ImageToolkit;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\ImageToolkit\Attribute\ImageToolkit;
use Drupal\Core\ImageToolkit\ImageToolkitOperationManagerInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file_mdm\FileMetadataManagerInterface;
use Drupal\imagemagick\Event\ImagemagickExecutionEvent;
use Drupal\imagemagick\ImagemagickExecManagerInterface;
use Drupal\imagemagick\ImagemagickFormatMapperInterface;
use Drupal\imagemagick\Plugin\ImageToolkit\ImagemagickToolkit;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Extended ImageMagick toolkit with FFmpeg support for animated PNGs.
 */
#[ImageToolkit(
  id: "imagemagick_extended",
  title: new TranslatableMarkup("ImageMagick Extended (with FFmpeg support)"),
)]
class ExtendedImagemagickToolkit extends ImagemagickToolkit {

  /**
   * Whether to use FFmpeg for animated PNG conversion.
   *
   * @var bool
   */
  protected bool $useFFmpegForAnimatedPng = FALSE;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    array $pluginDefinition,
    ImageToolkitOperationManagerInterface $operationManager,
    LoggerInterface $logger,
    ConfigFactoryInterface $configFactory,
    ImagemagickFormatMapperInterface $formatMapper,
    FileMetadataManagerInterface $fileMetadataManager,
    ImagemagickExecManagerInterface $execManager,
    EventDispatcherInterface $eventDispatcher,
  ) {
    parent::__construct(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $operationManager,
      $logger,
      $configFactory,
      $formatMapper,
      $fileMetadataManager,
      $execManager,
      $eventDispatcher
    );

    // Initialize FFmpeg usage based on configuration.
    $this->useFFmpegForAnimatedPng = (bool) $this->configFactory->get('imagemagick.settings')->get('ffmpeg_animated_png');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(ImageToolkitOperationManagerInterface::class),
      $container->get('logger.channel.image'),
      $container->get(ConfigFactoryInterface::class),
      $container->get(ImagemagickFormatMapperInterface::class),
      $container->get(FileMetadataManagerInterface::class),
      $container->get(ImagemagickExecManagerInterface::class),
      $container->get(EventDispatcherInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $config = $this->configFactory->getEditable('imagemagick.settings');

    // To prevent duplication of vertical tabs and settings, we need a unqiue group for this class's settings
    $group_name = 'imagemagick_ext_settings';
    $form[$group_name] = $form['imagemagick_settings'];
    $children = Element::children($form);
    // ksm($form);
    foreach ($children as $child_key) {
      if (in_array($child_key, ['imagemagick_settings', $group_name])) { continue; }
      $form[$child_key]['#group'] = $group_name;
    }

    // Add FFmpeg configuration options after the quality setting.
    $form['ffmpeg_animated_png'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use FFmpeg for animated PNG conversion'),
      '#default_value' => $config->get('ffmpeg_animated_png'),
      '#description' => $this->t('Enable FFmpeg support for converting animated PNGs. This preserves animation frames that ImageMagick might not handle properly. Requires FFmpeg to be installed on the server.'),
      '#weight' => -8,
    ];

    $form['ffmpeg_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to FFmpeg executable'),
      '#default_value' => $config->get('ffmpeg_path'),
      '#description' => $this->t('Full path to the FFmpeg executable. Leave empty if FFmpeg is in the system PATH.'),
      '#weight' => -7,
      '#states' => [
        'visible' => [
          ':input[name="imagemagick[ffmpeg_animated_png]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);

    // Validate FFmpeg path if FFmpeg support is enabled.
    if ($form_state->getValue(['imagemagick', 'ffmpeg_animated_png'])) {
      $ffmpeg_path = $form_state->getValue(['imagemagick', 'ffmpeg_path']);
      if (!$this->validateFFmpegPath($ffmpeg_path)) {
        $form_state->setErrorByName('imagemagick][ffmpeg_path', $this->t('FFmpeg executable not found at the specified path. Please check the path or disable FFmpeg support.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::submitConfigurationForm($form, $form_state);

    $config = $this->configFactory->getEditable('imagemagick.settings');
    $config
      ->set('ffmpeg_animated_png', (bool) $form_state->getValue([
        'imagemagick', 'ffmpeg_animated_png',
      ]))
      ->set('ffmpeg_path', (string) $form_state->getValue(['imagemagick', 'ffmpeg_path']))
      ->save();
  }

  /**
   * {@inheritdoc}
   */
  public function reset(int $width, int $height, string $format): static {
    parent::reset($width, $height, $format);

    // Set FFmpeg usage based on configuration.
    $this->useFFmpegForAnimatedPng = (bool) $this->configFactory->get('imagemagick.settings')->get('ffmpeg_animated_png');

    return $this;
  }

  /**
   * Validates the FFmpeg executable path.
   *
   * @param string $path
   *   The path to validate.
   *
   * @return bool
   *   TRUE if FFmpeg is accessible, FALSE otherwise.
   */
  protected function validateFFmpegPath(string $path): bool {
    $ffmpeg_command = $path ?: 'ffmpeg';

    try {
      $process = new Process([$ffmpeg_command, '-version']);
      $process->run();
      return $process->isSuccessful();
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if the image is an animated PNG.
   *
   * @return bool
   *   TRUE if the image is an animated PNG, FALSE otherwise.
   */
  protected function isAnimatedPng(): bool {
    return $this->arguments()->getSourceFormat() === 'PNG' &&
           ($this->getFrames() === NULL || $this->getFrames() > 1);
  }

  /**
   * Converts an animated PNG using FFmpeg.
   *
   * @return bool
   *   TRUE if the conversion was successful, FALSE otherwise.
   */
  protected function convertAnimatedPngWithFFmpeg(): bool {
    $source_path = $this->ensureSourceLocalPath();
    $destination_path = $this->arguments()->getDestinationLocalPath();

    if (!$destination_path) {
      // Generate destination path if not set.
      $destination_info = pathinfo($this->arguments()->getDestination());
      $temp_dir = sys_get_temp_dir();
      $destination_path = $temp_dir . '/' . uniqid('imagemagick_ffmpeg_') . '.' . $destination_info['extension'];
      $this->arguments()->setDestinationLocalPath($destination_path);
    }

    $ffmpeg_path = $this->configFactory->get('imagemagick.settings')->get('ffmpeg_path') ?: 'ffmpeg';

    // Determine output format based on destination.
    $destination_format = $this->arguments()->getDestinationFormat() ?: $this->arguments()->getSourceFormat();

    // Build FFmpeg command for animated PNG conversion.
    $command = [
      $ffmpeg_path,
      '-i', $source_path,
      '-y', // Overwrite output file
    ];

    // Add format-specific options.
    switch (strtoupper($destination_format)) {
      // case 'GIF':
      //   // Generate palette for better GIF quality.
      //   $command[] = '-vf';
      //   $command[] = 'fps=10,scale=-1:-1:flags=lanczos,palettegen=stats_mode=diff[palette],[0:v]fps=10,scale=-1:-1:flags=lanczos[v],[v][palette]paletteuse=dither=bayer:bayer_scale=3';
      //   break;

      // case 'WEBP':
      //   $command[] = '-c:v';
      //   $command[] = 'libwebp';
      //   $command[] = '-lossless';
      //   $command[] = '1';
      //   $command[] = '-loop';
      //   $command[] = '0';
      //   break;

      case 'PNG':
      default:
        // Keep as animated PNG (APNG).
        $command[] = '-f';
        $command[] = 'apng';
        $command[] = '-plays';
        $command[] = '0'; // Loop infinitely
        break;
    }

    // Add quality settings if configured.
    $quality = $this->configFactory->get('imagemagick.settings')->get('quality');
    if ($quality && $destination_format !== 'PNG') {
      $command[] = '-q:v';
      $command[] = (string) $quality;
    }

    $command[] = $destination_path;

    try {
      $process = new Process($command);
      $process->setTimeout(120); // 2 minute timeout
      $process->run();

      if ($process->isSuccessful() && file_exists($destination_path)) {
        $this->logger->info('FFmpeg animated PNG conversion successful: @command', [
          '@command' => $process->getCommandLine(),
        ]);
        return TRUE;
      } else {
        $this->logger->error('FFmpeg conversion failed. Command: @command, Error: @error', [
          '@command' => $process->getCommandLine(),
          '@error' => $process->getErrorOutput(),
        ]);
        return FALSE;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('FFmpeg conversion exception: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function convert(): bool {
    // Check if we should use FFmpeg for animated PNG conversion.
    if ($this->useFFmpegForAnimatedPng && $this->isAnimatedPng()) {
      $result = $this->convertAnimatedPngWithFFmpeg();
      if ($result) {
        // Allow modules to alter the destination file.
        $this->eventDispatcher->dispatch(
          new ImagemagickExecutionEvent($this->arguments),
          ImagemagickExecutionEvent::POST_SAVE
        );
      }
      return $result;
    }

    // Fall back to parent conversion method.
    return parent::convert();
  }

  /**
   * {@inheritdoc}
   */
  public function getRequirements(): array {
    $requirements = parent::getRequirements();

    // Add FFmpeg requirement check if enabled.
    if ($this->configFactory->get('imagemagick.settings')->get('ffmpeg_animated_png')) {
      $ffmpeg_path = $this->configFactory->get('imagemagick.settings')->get('ffmpeg_path');

      if ($this->validateFFmpegPath($ffmpeg_path)) {
        $requirements['imagemagick_ffmpeg'] = [
          'title' => $this->t('ImageMagick FFmpeg Support'),
          'value' => $this->t('FFmpeg available'),
          'description' => $this->t('FFmpeg is available for animated PNG conversion.'),
          'severity' => REQUIREMENT_OK,
        ];
      } else {
        $requirements['imagemagick_ffmpeg'] = [
          'title' => $this->t('ImageMagick FFmpeg Support'),
          'value' => $this->t('FFmpeg not found'),
          'description' => $this->t('FFmpeg is enabled for animated PNG support but the executable was not found. Animated PNGs will fall back to ImageMagick conversion (first frame only).'),
          'severity' => REQUIREMENT_WARNING,
        ];
      }
    }

    return $requirements;
  }
}
