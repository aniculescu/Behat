<?php

namespace Behat\Behat\Output\Formatter;

/*
 * This file is part of the Behat.
 * (c) Konstantin Kudryashov <ever.zet@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
use Behat\Behat\Definition\DefinitionInterface;
use Behat\Behat\Event\BackgroundEvent;
use Behat\Behat\Event\EventInterface;
use Behat\Behat\Event\ExerciseEvent;
use Behat\Behat\Event\FeatureEvent;
use Behat\Behat\Event\HookEvent;
use Behat\Behat\Event\OutlineEvent;
use Behat\Behat\Event\OutlineExampleEvent;
use Behat\Behat\Event\ScenarioEvent;
use Behat\Behat\Event\StepEvent;
use Behat\Behat\Exception\UndefinedException;
use Behat\Behat\Snippet\SnippetInterface;
use Behat\Gherkin\Node\BackgroundNode;
use Behat\Gherkin\Node\FeatureNode;
use Behat\Gherkin\Node\NodeInterface;
use Behat\Gherkin\Node\OutlineNode;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\ScenarioInterface;
use Behat\Gherkin\Node\ScenarioNode;
use Behat\Gherkin\Node\StepContainerInterface;
use Behat\Gherkin\Node\StepNode;
use Behat\Gherkin\Node\TableNode;
use Behat\Gherkin\Node\TaggedNodeInterface;

/**
 * Pretty formatter.
 *
 * @author Konstantin Kudryashov <ever.zet@gmail.com>
 */
class PrettyFormatter extends ProgressFormatter
{
    /**
     * Maximum line length.
     *
     * @var integer
     */
    protected $maxLineLength = 0;
    /**
     * Are we in background.
     *
     * @var Boolean
     */
    protected $inBackground = false;
    /**
     * Is background printed.
     *
     * @var Boolean
     */
    protected $isBackgroundPrinted = false;
    /**
     * Are we in outline steps.
     *
     * @var Boolean
     */
    protected $inOutlineSteps = false;
    /**
     * Are we in outline example.
     *
     * @var Boolean
     */
    protected $inOutlineExample = false;
    /**
     * Is outline headline printed.
     *
     * @var Boolean
     */
    protected $isOutlineHeaderPrinted = false;
    /**
     * Delayed scenario event.
     *
     * @var EventInterface
     */
    protected $delayedScenarioEvent;
    /**
     * Delayed step events.
     *
     * @var StepEvent[]
     */
    protected $delayedStepEvents = array();
    /**
     * Current step indentation.
     *
     * @var integer
     */
    protected $stepIndent = '    ';

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return array(
            EventInterface::BEFORE_EXERCISE        => array('beforeExercise', -50),
            EventInterface::AFTER_EXERCISE         => array('afterExercise', -50),
            EventInterface::BEFORE_FEATURE         => array('beforeFeature', -50),
            EventInterface::AFTER_FEATURE          => array('afterFeature', -50),
            EventInterface::BEFORE_SCENARIO        => array('beforeScenario', -50),
            EventInterface::AFTER_SCENARIO         => array('afterScenario', -50),
            EventInterface::BEFORE_BACKGROUND      => array('beforeBackground', -50),
            EventInterface::AFTER_BACKGROUND       => array('afterBackground', -50),
            EventInterface::BEFORE_OUTLINE         => array('beforeOutline', -50),
            EventInterface::AFTER_OUTLINE          => array('afterOutline', -50),
            EventInterface::BEFORE_OUTLINE_EXAMPLE => array('beforeOutlineExample', -50),
            EventInterface::AFTER_OUTLINE_EXAMPLE  => array('afterOutlineExample', -50),
            EventInterface::AFTER_STEP             => array('afterStep', -50),
            EventInterface::AFTER_HOOK             => array('afterHook', -50),
        );
    }

    /**
     * Returns formatter name.
     *
     * @return string
     */
    public function getName()
    {
        return 'pretty';
    }

    /**
     * Returns formatter description.
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Prints the feature as is.';
    }

    /**
     * @param HookEvent $event
     */
    public function afterHook(HookEvent $event)
    {
        $this->write($event->getStdOut());
    }

    /**
     * Listens to "suite.before" event.
     *
     * @param ExerciseEvent $event
     *
     * @uses printExerciseHeader()
     */
    public function beforeExercise(ExerciseEvent $event)
    {
        $this->printExerciseHeader();
    }

    /**
     * Listens to "suite.after" event.
     *
     * @param ExerciseEvent $event
     *
     * @uses printExerciseFooter()
     */
    public function afterExercise(ExerciseEvent $event)
    {
        $this->printExerciseFooter();
    }

    /**
     * Listens to "feature.before" event.
     *
     * @param FeatureEvent $event
     *
     * @uses printFeatureHeader()
     */
    public function beforeFeature(FeatureEvent $event)
    {
        $this->isBackgroundPrinted = false;
        $this->printFeatureHeader($event->getFeature());
    }

    /**
     * Listens to "feature.after" event.
     *
     * @param FeatureEvent $event
     *
     * @uses printFeatureFooter()
     */
    public function afterFeature(FeatureEvent $event)
    {
        $this->printFeatureFooter($event->getFeature());
    }

    /**
     * Listens to "background.before" event.
     *
     * @param BackgroundEvent $event
     *
     * @uses printBackgroundHeader()
     */
    public function beforeBackground(BackgroundEvent $event)
    {
        $this->inBackground = true;

        if ($this->isBackgroundPrinted) {
            return;
        }

        $this->printBackgroundHeader($event->getBackground());
    }

    /**
     * Listens to "background.after" event.
     *
     * @param BackgroundEvent $event
     *
     * @uses printBackgroundFooter()
     */
    public function afterBackground(BackgroundEvent $event)
    {
        $this->inBackground = false;

        if ($this->isBackgroundPrinted) {
            return;
        }
        $this->isBackgroundPrinted = true;

        $this->printBackgroundFooter($event->getBackground());

        if (null !== $this->delayedScenarioEvent) {
            $method = $this->delayedScenarioEvent[0];
            $event = $this->delayedScenarioEvent[1];

            $this->$method($event);
        }
    }

    /**
     * Listens to "outline.before" event.
     *
     * @param OutlineEvent $event
     *
     * @uses printOutlineHeader()
     */
    public function beforeOutline(OutlineEvent $event)
    {
        $outline = $event->getOutline();

        if (!$this->isBackgroundPrinted && $outline->getFeature()->hasBackground()) {
            $this->delayedScenarioEvent = array(__FUNCTION__, $event);

            return;
        }

        $this->isOutlineHeaderPrinted = false;

        $this->printOutlineHeader($outline);
    }

    /**
     * Listens to "outline.example.before" event.
     *
     * @param OutlineExampleEvent $event
     *
     * @uses printOutlineExampleHeader()
     */
    public function beforeOutlineExample(OutlineExampleEvent $event)
    {
        $this->inOutlineExample = true;
        $this->delayedStepEvents = array();

        $this->printOutlineExampleHeader($event->getOutline(), $event->getIteration());
    }

    /**
     * Listens to "outline.example.after" event.
     *
     * @param OutlineExampleEvent $event
     *
     * @uses printOutlineExampleFooter()
     */
    public function afterOutlineExample(OutlineExampleEvent $event)
    {
        $this->inOutlineExample = false;

        $this->printOutlineExampleFooter(
            $event->getOutline(), $event->getIteration(), $event->getStatus(), StepEvent::SKIPPED === $event->getStatus()
        );
    }

    /**
     * Listens to "outline.after" event.
     *
     * @param OutlineEvent $event
     *
     * @uses printOutlineFooter()
     */
    public function afterOutline(OutlineEvent $event)
    {
        $this->printOutlineFooter($event->getOutline());
    }

    /**
     * Listens to "scenario.before" event.
     *
     * @param ScenarioEvent $event
     *
     * @uses printScenarioHeader()
     */
    public function beforeScenario(ScenarioEvent $event)
    {
        $scenario = $event->getScenario();

        if (!$this->isBackgroundPrinted && $scenario->getFeature()->hasBackground()) {
            $this->delayedScenarioEvent = array(__FUNCTION__, $event);

            return;
        }

        $this->printScenarioHeader($scenario);
    }

    /**
     * Listens to "scenario.after" event.
     *
     * @param ScenarioEvent $event
     *
     * @uses printScenarioFooter()
     */
    public function afterScenario(ScenarioEvent $event)
    {
        $this->printScenarioFooter($event->getScenario());
    }

    /**
     * Listens to "step.after" event.
     *
     * @param StepEvent $event
     *
     * @uses printStep()
     */
    public function afterStep(StepEvent $event)
    {
        $this->write($event->getStdOut());

        if ($this->inBackground && $this->isBackgroundPrinted) {
            return;
        }

        if (!$this->inBackground && $this->inOutlineExample) {
            $this->delayedStepEvents[] = $event;

            return;
        }

        $this->printStep(
            $event->getStep(),
            $event->getStatus(),
            $event->getDefinition(),
            $event->getSnippet(),
            $event->getException()
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultParameters()
    {
        return array_merge(
            parent::getDefaultParameters(),
            array(
                'expand'              => false,
                'multiline_arguments' => true,
            )
        );
    }

    /**
     * Prints feature header.
     *
     * @param FeatureNode $feature
     *
     * @uses printFeatureOrScenarioTags()
     * @uses printFeatureName()
     * @uses printFeatureDescription()
     */
    protected function printFeatureHeader(FeatureNode $feature)
    {
        $this->printFeatureOrScenarioTags($feature);
        $this->printFeatureName($feature);
        if (null !== $feature->getDescription()) {
            $this->printFeatureDescription($feature);
        }

        $this->writeln();
    }

    /**
     * Prints node tags.
     *
     * @param TaggedNodeInterface $node
     */
    protected function printFeatureOrScenarioTags(TaggedNodeInterface $node)
    {
        if (!($node instanceof FeatureNode) && !($node instanceof ScenarioInterface)) {
            return;
        }

        $tags = $node instanceof ScenarioInterface ? $node->getOwnTags() : $node->getTags();

        if (count($tags)) {
            $tags = implode(' ', array_map(function ($tag) {
                return '@' . $tag;
            }, $tags));

            if ($node instanceof FeatureNode) {
                $indent = '';
            } else {
                $indent = '  ';
            }

            $this->writeln("$indent{+tag}$tags{-tag}");
        }
    }

    /**
     * Prints feature keyword and name.
     *
     * @param FeatureNode $feature
     *
     * @uses getFeatureOrScenarioName()
     */
    protected function printFeatureName(FeatureNode $feature)
    {
        $this->writeln($this->getFeatureOrScenarioName($feature));
    }

    /**
     * Prints feature description.
     *
     * @param FeatureNode $feature
     */
    protected function printFeatureDescription(FeatureNode $feature)
    {
        $lines = explode("\n", $feature->getDescription());

        foreach ($lines as $line) {
            $this->writeln("  $line");
        }
    }

    /**
     * Prints feature footer.
     *
     * @param FeatureNode $feature
     */
    protected function printFeatureFooter(FeatureNode $feature)
    {
    }

    /**
     * Prints scenario keyword and name.
     *
     * @param StepContainerInterface $scenario
     *
     * @uses getFeatureOrScenarioName()
     * @uses printScenarioPath()
     */
    protected function printScenarioName(StepContainerInterface $scenario)
    {
        $title = explode("\n", $this->getFeatureOrScenarioName($scenario));

        $this->write(array_shift($title));
        $this->printScenarioPath($scenario);

        if (count($title)) {
            $this->writeln(implode("\n", $title));
        }
    }

    /**
     * Prints scenario definition path.
     *
     * @param StepContainerInterface $scenario
     *
     * @uses getFeatureOrScenarioName()
     * @uses printPathComment()
     */
    protected function printScenarioPath(StepContainerInterface $scenario)
    {
        if ($this->getParameter('paths')) {
            $lines = explode("\n", $this->getFeatureOrScenarioName($scenario));
            $nameLength = mb_strlen(current($lines), 'utf8');
            $indentCount = $nameLength > $this->maxLineLength ? 0 : $this->maxLineLength - $nameLength;

            $this->printPathComment(
                $this->relativizePathsInString($scenario->getFile()) . ':' . $scenario->getLine(), $indentCount
            );
        } else {
            $this->writeln();
        }
    }

    /**
     * Prints background header.
     *
     * @param BackgroundNode $background
     *
     * @uses printScenarioName()
     * @uses printScenarioPath()
     */
    protected function printBackgroundHeader(BackgroundNode $background)
    {
        $this->maxLineLength = $this->getMaxLineLength($this->maxLineLength, $background);

        $this->printScenarioName($background);
    }

    /**
     * Prints background footer.
     *
     * @param BackgroundNode $background
     */
    protected function printBackgroundFooter(BackgroundNode $background)
    {
        $this->writeln();
    }

    /**
     * Prints outline header.
     *
     * @param OutlineNode $outline
     *
     * @uses printFeatureOrScenarioTags()
     * @uses printScenarioName()
     */
    protected function printOutlineHeader(OutlineNode $outline)
    {
        $this->maxLineLength = $this->getMaxLineLength($this->maxLineLength, $outline);

        $this->printFeatureOrScenarioTags($outline);
        $this->printScenarioName($outline);
    }

    /**
     * Prints outline footer.
     *
     * @param OutlineNode $outline
     */
    protected function printOutlineFooter(OutlineNode $outline)
    {
        $this->writeln();
    }

    /**
     * Prints outline example header.
     *
     * @param OutlineNode $outline
     * @param integer     $iteration
     */
    protected function printOutlineExampleHeader(OutlineNode $outline, $iteration)
    {
    }

    /**
     * Prints outline example result.
     *
     * @param OutlineNode $outline   outline instance
     * @param integer     $iteration example row number
     * @param integer     $result    result code
     * @param Boolean     $skipped   is outline example skipped
     *
     * @uses printOutlineSteps()
     * @uses printOutlineExamplesSectionHeader()
     * @uses printOutlineExampleResult()
     */
    protected function printOutlineExampleFooter(OutlineNode $outline, $iteration, $result, $skipped)
    {
        if (!$this->isOutlineHeaderPrinted) {
            $this->printOutlineSteps($outline);
            $this->printOutlineExamplesSectionHeader($outline->getExampleTable());
            $this->isOutlineHeaderPrinted = true;
        }

        $this->printOutlineExampleResult($outline->getExampleTable(), $iteration, $result, $skipped);
    }

    /**
     * Prints outline steps.
     *
     * @param OutlineNode $outline
     */
    protected function printOutlineSteps(OutlineNode $outline)
    {
        $this->inOutlineSteps = true;

        foreach ($this->delayedStepEvents as $event) {
            $this->printStep($event->getStep(), StepEvent::SKIPPED, $event->getDefinition());
        }

        $this->inOutlineSteps = false;
    }

    /**
     * Prints outline examples header.
     *
     * @param TableNode $examples
     *
     * @uses printColorizedTableRow()
     */
    protected function printOutlineExamplesSectionHeader(TableNode $examples)
    {
        $this->writeln();
        $keyword = $examples->getKeyword();

        if (!$this->getParameter('expand')) {
            $this->writeln("    $keyword:");
            $this->printColorizedTableRow($examples->getRowAsString(0), 'skipped');
        }
    }

    /**
     * Prints outline example result.
     *
     * @param TableNode $examples  examples table
     * @param integer   $iteration example row
     * @param integer   $result    result code
     * @param boolean   $isSkipped is outline example skipped
     *
     * @uses printColorizedTableRow()
     * @uses printOutlineExampleResultExceptions()
     */
    protected function printOutlineExampleResult(TableNode $examples, $iteration, $result, $isSkipped)
    {
        if (!$this->getParameter('expand')) {
            $color = $this->getResultColorCode($result);

            $this->printColorizedTableRow($examples->getRowAsString($iteration), $color);
            $this->printOutlineExampleResultExceptions($examples, $this->delayedStepEvents);
        } else {
            $this->write('      ' . $examples->getKeyword() . ': ');
            $this->writeln('| ' . implode(' | ', $examples->getRow($iteration)) . ' |');

            $this->stepIndent = '        ';
            foreach ($this->delayedStepEvents as $event) {
                $this->printStep(
                    $event->getStep(),
                    $event->getStatus(),
                    $event->getDefinition(),
                    $event->getSnippet(),
                    $event->getException()
                );
            }
            $this->stepIndent = '    ';

            if ($iteration < count($examples->getRows()) - 1) {
                $this->writeln();
            }
        }
    }

    /**
     * Prints outline example exceptions.
     *
     * @param TableNode   $examples examples table
     * @param StepEvent[] $events   failed steps events
     */
    protected function printOutlineExampleResultExceptions(TableNode $examples, array $events)
    {
        foreach ($events as $event) {
            $exception = $event->getException();
            if ($exception && !$exception instanceof UndefinedException) {
                $color = $this->getResultColorCode($event->getStatus());

                $error = $this->exceptionToString($exception);
                $error = $this->relativizePathsInString($error);

                $this->writeln(
                    "        {+$color}" . strtr($error, array("\n" => "\n      ")) . "{-$color}"
                );
            }
        }
    }

    /**
     * Prints scenario header.
     *
     * @param ScenarioNode $scenario
     *
     * @uses printFeatureOrScenarioTags()
     * @uses printScenarioName()
     */
    protected function printScenarioHeader(ScenarioNode $scenario)
    {
        $this->maxLineLength = $this->getMaxLineLength($this->maxLineLength, $scenario);

        $this->printFeatureOrScenarioTags($scenario);
        $this->printScenarioName($scenario);
    }

    /**
     * Prints scenario footer.
     *
     * @param ScenarioNode $scenario
     */
    protected function printScenarioFooter(ScenarioNode $scenario)
    {
        $this->writeln();
    }

    /**
     * Prints step.
     *
     * @param StepNode            $step       step node
     * @param integer             $result     result code
     * @param DefinitionInterface $definition definition (if found one)
     * @param string              $snippet    snippet (if step is undefined)
     * @param \Exception          $exception  exception (if step is failed)
     *
     * @uses printStepBlock()
     * @uses printStepArguments()
     * @uses printStepException()
     * @uses printStepSnippet()
     */
    protected function printStep(
        StepNode $step,
        $result,
        DefinitionInterface $definition = null,
        $snippet = null,
        \Exception $exception = null
    )
    {
        $color = $this->getResultColorCode($result);

        $this->printStepBlock($step, $definition, $color);

        if ($this->getParameter('multiline_arguments')) {
            $this->printStepArguments($step->getArguments(), $color);
        }
        if (null !== $exception &&
            (!$exception instanceof UndefinedException || null === $snippet)
        ) {
            $this->printStepException($exception, $color);
        }
        if (null !== $snippet && $this->getParameter('snippets')) {
            $this->printStepSnippet($snippet);
        }
    }

    /**
     * Prints step block (name & definition path).
     *
     * @param StepNode            $step       step node
     * @param DefinitionInterface $definition definition (if found one)
     * @param string              $color      color code
     *
     * @uses printStepName()
     * @uses printStepDefinitionPath()
     */
    protected function printStepBlock(StepNode $step, DefinitionInterface $definition = null, $color)
    {
        $this->printStepName($step, $definition, $color);
        if (null !== $definition) {
            $this->printStepDefinitionPath($step, $definition);
        } else {
            $this->writeln();
        }
    }

    /**
     * Prints step name.
     *
     * @param StepNode            $step       step node
     * @param DefinitionInterface $definition definition (if found one)
     * @param string              $color      color code
     *
     * @uses colorizeDefinitionArguments()
     */
    protected function printStepName(StepNode $step, DefinitionInterface $definition = null, $color)
    {
        $type = $step->getType();
        $text = $step->getText();

        if ($this->inOutlineSteps) {
            $index = array_search($step, $step->getContainer()->getSteps());
            $steps = $step->getContainer()->getOutline()->getSteps();
            $text = $steps[$index]->getText();
        }

        $indent = $this->stepIndent;

        if (null !== $definition) {
            $text = $this->colorizeDefinitionArguments($text, $definition, $color);
        }

        $this->write("$indent{+$color}$type $text{-$color}");
    }

    /**
     * Prints step definition path.
     *
     * @param StepNode            $step       step node
     * @param DefinitionInterface $definition definition (if found one)
     *
     * @uses printPathComment()
     */
    protected function printStepDefinitionPath(StepNode $step, DefinitionInterface $definition)
    {
        if ($this->getParameter('paths')) {
            $type = $step->getType();
            $text = $step->getText();

            if ($this->inOutlineSteps) {
                $index = array_search($step, $step->getContainer()->getSteps());
                $steps = $step->getContainer()->getOutline()->getSteps();
                $text = $steps[$index]->getText();
            }

            $indent = $this->stepIndent;
            $nameLength = mb_strlen("$indent$type $text", 'utf8');
            $indentCount = $nameLength > $this->maxLineLength ? 0 : $this->maxLineLength - $nameLength;

            $this->printPathComment(
                $this->relativizePathsInString($definition->getPath()), $indentCount
            );

            if ($this->getParameter('expand')) {
                $this->maxLineLength = max($this->maxLineLength, $nameLength);
            }
        } else {
            $this->writeln();
        }
    }

    /**
     * Prints step arguments.
     *
     * @param array  $arguments step arguments
     * @param string $color     color name
     *
     * @uses printStepPyStringArgument()
     * @uses printStepTableArgument()
     */
    protected function printStepArguments(array $arguments, $color)
    {
        foreach ($arguments as $argument) {
            if ($argument instanceof PyStringNode) {
                $this->printStepPyStringArgument($argument, $color);
            } elseif ($argument instanceof TableNode) {
                $this->printStepTableArgument($argument, $color);
            }
        }
    }

    /**
     * Prints step exception.
     *
     * @param \Exception $exception
     * @param string     $color
     */
    protected function printStepException(\Exception $exception, $color)
    {
        $indent = $this->stepIndent;

        $error = $this->exceptionToString($exception);
        $error = $this->relativizePathsInString($error);

        $this->writeln(
            "$indent  {+$color}" . strtr($error, array("\n" => "\n$indent  ")) . "{-$color}"
        );
    }

    /**
     * Prints step snippet
     *
     * @param SnippetInterface $snippet
     */
    protected function printStepSnippet(SnippetInterface $snippet)
    {
    }

    /**
     * Prints PyString argument.
     *
     * @param PyStringNode $pystring pystring node
     * @param string       $color    color name
     */
    protected function printStepPyStringArgument(PyStringNode $pystring, $color = null)
    {
        $indent = $this->stepIndent;
        $string = strtr(
            sprintf("$indent  \"\"\"\n%s\n\"\"\"", (string)$pystring), array("\n" => "\n$indent  ")
        );

        if (null !== $color) {
            $this->writeln("{+$color}$string{-$color}");
        } else {
            $this->writeln($string);
        }
    }

    /**
     * Prints table argument.
     *
     * @param TableNode $table
     * @param string    $color
     */
    protected function printStepTableArgument(TableNode $table, $color = null)
    {
        $indent = $this->stepIndent;
        $string = strtr("$indent  " . (string)$table, array("\n" => "\n$indent  "));

        if (null !== $color) {
            $this->writeln("{+$color}$string{-$color}");
        } else {
            $this->writeln($string);
        }
    }

    /**
     * Prints table row in color.
     *
     * @param array  $row
     * @param string $color
     */
    protected function printColorizedTableRow($row, $color)
    {
        $string = preg_replace(
            '/|([^|]*)|/',
            "{+$color}\$1{-$color}",
            '      ' . $row
        );

        $this->writeln($string);
    }

    /**
     * Prints suite header.
     */
    protected function printExerciseHeader()
    {
    }

    /**
     * Prints suite footer information.
     *
     * @uses printSummary()
     * @uses printUndefinedStepsSnippets()
     */
    protected function printExerciseFooter()
    {
        $this->printSummary($this->getStatisticsCollector());
        $this->printUndefinedStepsSnippets($this->getSnippetsCollector());
    }

    /**
     * Returns feature or scenario name.
     *
     * @param NodeInterface $node
     * @param Boolean       $haveBaseIndent
     *
     * @return string
     */
    protected function getFeatureOrScenarioName(NodeInterface $node, $haveBaseIndent = true)
    {
        $keyword = $node->getKeyword();
        $baseIndent = ($node instanceof FeatureNode) || !$haveBaseIndent ? '' : '  ';

        $lines = explode("\n", $node->getTitle());
        $title = array_shift($lines);

        if (count($lines)) {
            foreach ($lines as $line) {
                $title .= "\n" . $baseIndent . '  ' . $line;
            }
        }

        return "$baseIndent$keyword:" . ($title ? ' ' . $title : '');
    }

    /**
     * Returns step text with colorized arguments.
     *
     * @param string              $text
     * @param DefinitionInterface $definition
     * @param string              $color
     *
     * @return string
     */
    protected function colorizeDefinitionArguments($text, DefinitionInterface $definition, $color)
    {
        $regex = $definition->getRegex();
        $paramColor = $color . '_param';

        // If it's just a string - skip
        if ('/' !== substr($regex, 0, 1)) {
            return $text;
        }

        // Find arguments with offsets
        $matches = array();
        preg_match($regex, $text, $matches, PREG_OFFSET_CAPTURE);
        array_shift($matches);

        // Replace arguments with colorized ones
        $shift = 0;
        $lastReplacementPosition = 0;
        foreach ($matches as $key => $match) {
            if (!is_numeric($key) || -1 === $match[1] || false !== strpos($match[0], '<')) {
                continue;
            }

            $offset = $match[1] + $shift;
            $value = $match[0];

            // Skip inner matches
            if ($lastReplacementPosition > $offset) {
                continue;
            }
            $lastReplacementPosition = $offset + strlen($value);

            $begin = substr($text, 0, $offset);
            $end = substr($text, $lastReplacementPosition);
            $format = "{-$color}{+$paramColor}%s{-$paramColor}{+$color}";
            $text = sprintf("%s{$format}%s", $begin, $value, $end);

            // Keep track of how many extra characters are added
            $shift += strlen($format) - 2;
            $lastReplacementPosition += strlen($format) - 2;
        }

        // Replace "<", ">" with colorized ones
        $text = preg_replace('/(<[^>]+>)/',
            "{-$color}{+$paramColor}\$1{-$paramColor}{+$color}",
            $text
        );

        return $text;
    }

    /**
     * Returns max lines size for section elements.
     *
     * @param integer                $max      previous max value
     * @param StepContainerInterface $scenario element for calculations
     *
     * @return integer
     */
    protected function getMaxLineLength($max, StepContainerInterface $scenario)
    {
        $lines = explode("\n", $this->getFeatureOrScenarioName($scenario, false));
        $max = max($max, mb_strlen(current($lines), 'utf8') + 2);

        foreach ($scenario->getSteps() as $step) {
            $text = $step->getText();
            $stepDescription = $step->getType() . ' ' . $text;
            $max = max($max, mb_strlen($stepDescription, 'utf8') + 4);
        }

        return $max;
    }
}
