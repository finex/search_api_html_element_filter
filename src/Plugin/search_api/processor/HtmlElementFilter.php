<?php

namespace Drupal\search_api_html_element_filter\Plugin\search_api\processor;

use Drupal\search_api\Plugin\search_api\data_type\value\TextValueInterface;
use Drupal\search_api\Query\ResultSetInterface;
use Symfony\Component\CssSelector\Exception\SyntaxErrorException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\Processor\FieldsProcessorPluginBase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Removes div elements from indexing in search API.
 *
 * @SearchApiProcessor(
 *   id = "html_element_filter",
 *   label = @Translation("HTML Element Filter"),
 *   description = @Translation("Removes div elements from indexing in search API"),
 *   stages = {
 *     "pre_index_save" = 0,
 *     "preprocess_index" = -30,
 *     "postprocess_query" = -30,
 *   }
 * )
 */
class HtmlElementFilter extends FieldsProcessorPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $configuration = parent::defaultConfiguration();
    $configuration += [
      'css_selectors' => '',
    ];
    return $configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);
    $css_selectors = $form_state->getValue('css_selectors');
    $css_selectors = explode("\r\n", $css_selectors);
    $crawler = new Crawler('<body></body>');
    $errors = [];
    foreach ($css_selectors as $css_selector) {
      if (empty($css_selector)) {
        continue;
      }
      try {
        @$crawler->filter($css_selector);
      }
      catch (SyntaxErrorException $e) {
        $errors[] = $this->t('Invalid CSS Selector: @tag', [
          '@tag' => $css_selector,
        ]);
      }
    }

    if ($errors) {
      $form_state->setError($form['css_selectors'], implode('<br>', $errors));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['css_selectors'] = [
      '#type' => 'textarea',
      '#title' => $this->t('CSS Selectors'),
      '#description' => $this->t('Specify CSS selectors for the elements that need to be removed from the markup. Separate each selector on a new line. E.g. <b>.sidebar-filters</b>'),
      '#default_value' => $this->configuration['css_selectors'],
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function process(&$value) {
    $value = $this->removeHtmlElements($value, $this->getConfiguration()['css_selectors']);
  }

  /**
   * {@inheritdoc}
   */
  public function postprocessSearchResults(ResultSetInterface $results) {
    foreach ($results->getResultItems() as $item) {
      foreach ($item->getFields() as $name => $field) {
        if (!$this->testField($name, $field) || !$this->testType($field->getType())) {
          continue;
        }

        $values = $field->getValues();
        foreach ($values as $key => $value) {
          /** @var \Drupal\search_api\Plugin\search_api\data_type\value\TextValueInterface $value */
          if (is_string($value)) {
            $values[$key] = $this->removeHtmlElements($value, $this->getConfiguration()['css_selectors']);
          }
          elseif ($value instanceof TextValueInterface) {
            $value->setText($this->removeHtmlElements($value->getText(), $this->getConfiguration()['css_selectors']));
          }
        }
      }
    }
  }

  /**
   * Remove HTML elements from a markup.
   *
   * @param string $markup
   *   The HTML markup.
   * @param mixed $css_selectors
   *   The CSS selectors of the elements to be removed.
   *
   * @return string
   *   The HTML markup without the selected elements.
   */
  protected function removeHtmlElements(string $markup, $css_selectors) {
    $css_selectors = explode("\r\n", $css_selectors);
    $crawler = new Crawler($markup);
    foreach ($css_selectors as $css_selector) {
      if (empty($css_selector)) {
        continue;
      }

      try {
        @$crawler->filter($css_selector)->each(function (Crawler $crawler) {
          $node = $crawler->getNode(0);
          $node->parentNode->removeChild($node);
        });
      }
      catch (SyntaxErrorException|\InvalidArgumentException $e) {
      }
    }
    return $crawler->filter('body')->html();
  }

}
