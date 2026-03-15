<?php

	declare(strict_types=1);

	/**
	 * generate-api.php
	 *
	 * Build-time script that parses SharkordPHP DocBlocks and generates
	 * Starlight-compatible Markdown files for the API reference section.
	 *
	 * Run this before the Astro build step. The GitHub Actions workflow
	 * handles this automatically on every push to main.
	 *
	 * Usage:
	 *   php scripts/generate-api.php [--src=<path>] [--out=<path>]
	 *
	 * Options:
	 *   --src   Path to the SharkordPHP /src directory.
	 *           Default: ../SharkordPHP/src
	 *   --out   Output directory for generated .md files.
	 *           Default: src/content/docs/api
	 */

	require __DIR__ . '/../vendor/autoload.php';

	use PhpParser\Node;
	use PhpParser\NodeFinder;
	use PhpParser\ParserFactory;
	use phpDocumentor\Reflection\DocBlockFactory;
	use phpDocumentor\Reflection\DocBlock\Tags\Param;
	use phpDocumentor\Reflection\DocBlock\Tags\Return_;
	use phpDocumentor\Reflection\DocBlock\Tags\Throws;
	use phpDocumentor\Reflection\DocBlock\Tags\PropertyRead;

	// ---------------------------------------------------------------------------
	// Data Transfer Objects
	// ---------------------------------------------------------------------------

	/**
	 * Holds parsed information about a single class property or @property-read tag.
	 */
	final class PropertyInfo {

		/**
		 * @param string $name        Property name including the leading dollar sign.
		 * @param string $type        Declared type string (e.g. 'string', '?int').
		 * @param string $description One-line description from the docblock.
		 * @param bool   $readOnly    True when sourced from @property-read or readonly modifier.
		 */
		public function __construct(
			public readonly string $name,
			public readonly string $type,
			public readonly string $description,
			public readonly bool   $readOnly = false,
		) {}

	}

	/**
	 * Holds parsed information about a single method parameter.
	 */
	final class ParamInfo {

		/**
		 * @param string $name        Parameter name including the leading dollar sign.
		 * @param string $type        Declared type string.
		 * @param string $description Description from the matching @param tag.
		 * @param bool   $optional    True when the parameter has a default value.
		 * @param bool   $variadic    True for variadic (...$param) parameters.
		 */
		public function __construct(
			public readonly string $name,
			public readonly string $type,
			public readonly string $description,
			public readonly bool   $optional = false,
			public readonly bool   $variadic  = false,
		) {}

	}

	/**
	 * Holds parsed @throws information for a method.
	 */
	final class ThrowsInfo {

		/**
		 * @param string $type        The exception class name.
		 * @param string $description When the exception is thrown.
		 */
		public function __construct(
			public readonly string $type,
			public readonly string $description,
		) {}

	}

	/**
	 * Holds parsed information about a single class constant.
	 */
	final class ConstantInfo {

		/**
		 * @param string   $name        Constant name (e.g. 'MESSAGE_CREATE').
		 * @param string   $value       The constant's scalar value as a string.
		 * @param string   $summary     First line of the docblock.
		 * @param string   $description Extended description body.
		 * @param string[] $examples    Raw example code blocks.
		 */
		public function __construct(
			public readonly string $name,
			public readonly string $value,
			public readonly string $summary,
			public readonly string $description,
			public readonly array  $examples,
		) {}

	}

	/**
	 * Holds all parsed information about a single class method.
	 */
	final class MethodInfo {

		/**
		 * @param string       $name              Method name.
		 * @param string       $summary           First line of the docblock.
		 * @param string       $description       Extended description body.
		 * @param ParamInfo[]  $params            Parsed parameter list.
		 * @param string       $returnType        Return type string.
		 * @param string       $returnDescription Description from @return tag.
		 * @param ThrowsInfo[] $throws            Parsed @throws entries.
		 * @param string[]     $examples          Raw example code blocks (with ``` fences).
		 * @param bool         $isStatic          True for static methods.
		 * @param bool         $isAbstract        True for abstract methods.
		 */
		public function __construct(
			public readonly string $name,
			public readonly string $summary,
			public readonly string $description,
			public readonly array  $params,
			public readonly string $returnType,
			public readonly string $returnDescription,
			public readonly array  $throws,
			public readonly array  $examples,
			public readonly bool   $isStatic   = false,
			public readonly bool   $isAbstract = false,
		) {}

	}

	/**
	 * Holds all parsed information about a class, interface, or enum declaration.
	 */
	final class ClassInfo {

		/**
		 * @param string          $name        Short class name (no namespace).
		 * @param string          $namespace   Fully-qualified namespace string.
		 * @param string          $type        One of: 'class', 'interface', 'enum'.
		 * @param string          $summary     First line of the class docblock.
		 * @param string          $description Extended description body.
		 * @param PropertyInfo[]  $properties  Parsed properties and @property-read tags.
		 * @param MethodInfo[]    $methods     Parsed public, non-internal methods.
		 * @param string[]        $examples    Class-level example blocks.
		 * @param string[]        $enumCases   Formatted enum cases (e.g. "TEXT = 'TEXT'").
		 * @param ConstantInfo[]  $constants   Parsed public class constants.
		 */
		public function __construct(
			public readonly string $name,
			public readonly string $namespace,
			public readonly string $type,
			public readonly string $summary,
			public readonly string $description,
			public readonly array  $properties,
			public readonly array  $methods,
			public readonly array  $examples,
			public readonly array  $enumCases  = [],
			public readonly array  $constants  = [],
		) {}

		/**
		 * Returns the fully-qualified class name.
		 *
		 * @return string
		 */
		public function fqcn(): string {

			return $this->namespace !== '' ? $this->namespace . '\\' . $this->name : $this->name;

		}

	}

	// ---------------------------------------------------------------------------
	// Generator
	// ---------------------------------------------------------------------------

	/**
	 * Parses all PHP source files in a directory and emits Starlight-compatible
	 * Markdown documentation files, one per class/interface/enum.
	 *
	 * Classes in the Sharkord\Internal namespace are silently excluded, as are
	 * any methods or classes tagged @internal.
	 */
	final class ApiGenerator {

		private \PhpParser\Parser $parser;
		private NodeFinder        $nodeFinder;
		private DocBlockFactory   $docBlockFactory;

		/**
		 * Namespaces whose classes are excluded from the generated output entirely.
		 *
		 * @var string[]
		 */
		private const EXCLUDED_NAMESPACES = [
			'Sharkord\\Internal',
		];

		/**
		 * Maps namespace strings to output subdirectory names under the api/ root.
		 * Namespaces not listed here are written directly into the api/ root.
		 *
		 * @var array<string, string>
		 */
		private const NAMESPACE_DIR_MAP = [
			'Sharkord\\Models'      => 'models',
			'Sharkord\\Managers'    => 'managers',
			'Sharkord\\Collections' => 'collections',
			'Sharkord\\Commands'    => 'commands',
			'Sharkord\\Builders'	=> 'builders',
		];

		/**
		 * ApiGenerator constructor.
		 *
		 * @param string $srcPath Absolute path to the SharkordPHP /src directory.
		 * @param string $outPath Absolute path to the Starlight docs/api output directory.
		 */
		public function __construct(
			private readonly string $srcPath,
			private readonly string $outPath,
		) {

			$this->parser          = (new ParserFactory())->createForNewestSupportedVersion();
			$this->nodeFinder      = new NodeFinder();
			$this->docBlockFactory = DocBlockFactory::createInstance();

		}

		/**
		 * Runs the generator: scans the source tree and writes all Markdown files.
		 *
		 * @return void
		 */
		public function run(): void {

			$files = $this->findPhpFiles($this->srcPath);

			if (empty($files)) {
				$this->log("No PHP files found in: {$this->srcPath}");
				return;
			}

			$this->log(sprintf("Found %d PHP file(s) to process.\n", count($files)));

			$generated = 0;

			foreach ($files as $file) {

				foreach ($this->parseFile($file) as $info) {
					$this->writeDoc($info);
					$generated++;
				}

			}

			$this->log(sprintf("\nGenerated %d documentation file(s).", $generated));

		}

		// -----------------------------------------------------------------------
		// File discovery
		// -----------------------------------------------------------------------

		/**
		 * Recursively finds all .php files under the given directory.
		 *
		 * @param  string   $directory Absolute path to scan.
		 * @return string[]            Sorted list of absolute file paths.
		 */
		private function findPhpFiles(string $directory): array {

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
			);

			$files = [];

			foreach ($iterator as $file) {
				if ($file->isFile() && $file->getExtension() === 'php') {
					$files[] = $file->getRealPath();
				}
			}

			sort($files);

			return $files;

		}

		// -----------------------------------------------------------------------
		// Parsing
		// -----------------------------------------------------------------------

		/**
		 * Parses a single PHP file and returns ClassInfo objects for each
		 * top-level class, interface, or enum declaration it contains.
		 *
		 * @param  string      $filePath Absolute path to the PHP file.
		 * @return ClassInfo[]
		 */
		private function parseFile(string $filePath): array {

			$code = file_get_contents($filePath);

			if ($code === false) {
				$this->log("  [WARN] Could not read: {$filePath}");
				return [];
			}

			try {
				$stmts = $this->parser->parse($code) ?? [];
			} catch (\PhpParser\Error $e) {
				$this->log("  [WARN] Parse error in {$filePath}: {$e->getMessage()}");
				return [];
			}

			$namespace = $this->extractNamespace($stmts);

			if ($this->isExcludedNamespace($namespace)) {
				return [];
			}

			$results = [];

			foreach ($this->nodeFinder->findInstanceOf($stmts, Node\Stmt\Class_::class) as $node) {
				if ($info = $this->processClass($node, $namespace)) {
					$results[] = $info;
				}
			}

			foreach ($this->nodeFinder->findInstanceOf($stmts, Node\Stmt\Interface_::class) as $node) {
				if ($info = $this->processInterface($node, $namespace)) {
					$results[] = $info;
				}
			}

			foreach ($this->nodeFinder->findInstanceOf($stmts, Node\Stmt\Enum_::class) as $node) {
				if ($info = $this->processEnum($node, $namespace)) {
					$results[] = $info;
				}
			}

			return $results;

		}

		/**
		 * Extracts the namespace string from a parsed statement list.
		 *
		 * @param  Node\Stmt[] $stmts
		 * @return string              Empty string for the global namespace.
		 */
		private function extractNamespace(array $stmts): string {

			$ns = $this->nodeFinder->findFirstInstanceOf($stmts, Node\Stmt\Namespace_::class);

			return $ns?->name?->toString() ?? '';

		}

		/**
		 * Returns true when the given namespace should be excluded from output.
		 *
		 * @param string $namespace
		 * @return bool
		 */
		private function isExcludedNamespace(string $namespace): bool {

			foreach (self::EXCLUDED_NAMESPACES as $excluded) {
				if ($namespace === $excluded || str_starts_with($namespace, $excluded . '\\')) {
					return true;
				}
			}

			return false;

		}

		/**
		 * Processes a class declaration node into a ClassInfo object.
		 *
		 * @param  Node\Stmt\Class_ $node
		 * @param  string           $namespace
		 * @return ClassInfo|null              Null for anonymous classes or @internal classes.
		 */
		private function processClass(Node\Stmt\Class_ $node, string $namespace): ?ClassInfo {

			if ($node->name === null) {
				return null;
			}

			$rawComment = $node->getDocComment()?->getText() ?? '';

			[$summary, $description, $magicProperties, $examples, $isInternal] =
				$this->parseClassDocBlock($rawComment);

			if ($isInternal) {
				return null;
			}

			$declaredProperties = [];

			foreach ($node->stmts as $stmt) {

				if (!($stmt instanceof Node\Stmt\Property)) {
					continue;
				}

				// Skip private properties — users don't interact with them.
				if ($stmt->flags & Node\Stmt\Class_::MODIFIER_PRIVATE) {
					continue;
				}

				$propSummary    = '';
				$propRawComment = $stmt->getDocComment()?->getText() ?? '';

				if ($propRawComment !== '') {
					try {
						$propSummary = $this->docBlockFactory->create($propRawComment)->getSummary();
					} catch (\Throwable) {}
				}

				foreach ($stmt->props as $prop) {
					$declaredProperties[] = new PropertyInfo(
						name:     '$' . $prop->name->name,
						type:     $stmt->type ? $this->typeToString($stmt->type) : 'mixed',
						description: $propSummary,
						readOnly: (bool) ($stmt->flags & Node\Stmt\Class_::MODIFIER_READONLY),
					);
				}

			}

			// Magic @property-read entries come first, then declared properties.
			$properties = [...$magicProperties, ...$declaredProperties];
			$methods    = $this->parseMethods($node->stmts);
			$constants  = $this->parseConstants($node->stmts);

			return new ClassInfo(
				name:        $node->name->name,
				namespace:   $namespace,
				type:        'class',
				summary:     $summary,
				description: $description,
				properties:  $properties,
				methods:     $methods,
				examples:    $examples,
				constants:   $constants,
			);

		}

		/**
		 * Processes an interface declaration node into a ClassInfo object.
		 *
		 * @param  Node\Stmt\Interface_ $node
		 * @param  string               $namespace
		 * @return ClassInfo|null
		 */
		private function processInterface(Node\Stmt\Interface_ $node, string $namespace): ?ClassInfo {

			$rawComment = $node->getDocComment()?->getText() ?? '';

			[$summary, $description, $properties, $examples, $isInternal] =
				$this->parseClassDocBlock($rawComment);

			if ($isInternal) {
				return null;
			}

			return new ClassInfo(
				name:        $node->name->name,
				namespace:   $namespace,
				type:        'interface',
				summary:     $summary,
				description: $description,
				properties:  $properties,
				methods:     $this->parseMethods($node->stmts),
				examples:    $examples,
			);

		}

		/**
		 * Processes an enum declaration node into a ClassInfo object.
		 *
		 * @param  Node\Stmt\Enum_ $node
		 * @param  string          $namespace
		 * @return ClassInfo|null
		 */
		private function processEnum(Node\Stmt\Enum_ $node, string $namespace): ?ClassInfo {

			$rawComment = $node->getDocComment()?->getText() ?? '';

			[$summary, $description, $properties, $examples, $isInternal] =
				$this->parseClassDocBlock($rawComment);

			if ($isInternal) {
				return null;
			}

			$cases = [];

			foreach ($node->stmts as $stmt) {

				if (!($stmt instanceof Node\Stmt\EnumCase)) {
					continue;
				}

				$value = match (true) {
					$stmt->expr instanceof Node\Scalar\String_ => "'{$stmt->expr->value}'",
					$stmt->expr instanceof Node\Scalar\Int_    => (string) $stmt->expr->value,
					default                                    => '',
				};

				$cases[] = $stmt->name->name . ($value !== '' ? " = {$value}" : '');

			}

			return new ClassInfo(
				name:        $node->name->name,
				namespace:   $namespace,
				type:        'enum',
				summary:     $summary,
				description: $description,
				properties:  $properties,
				methods:     [],
				examples:    $examples,
				enumCases:   $cases,
			);

		}

		/**
		 * Parses the docblock attached to a class, interface, or enum.
		 *
		 * @param  string $rawComment Raw docblock text (may be empty).
		 * @return array{string, string, PropertyInfo[], string[], bool}
		 *               [summary, description, properties, examples, isInternal]
		 */
		private function parseClassDocBlock(string $rawComment): array {

			$summary    = '';
			$description = '';
			$properties = [];
			$examples   = [];
			$isInternal = false;

			if ($rawComment === '') {
				return [$summary, $description, $properties, $examples, $isInternal];
			}

			try {

				$docBlock   = $this->docBlockFactory->create($rawComment);
				$summary    = $docBlock->getSummary();
				$description = (string) $docBlock->getDescription();
				$isInternal = count($docBlock->getTagsByName('internal')) > 0;

				foreach ($docBlock->getTagsByName('property-read') as $tag) {
					/** @var PropertyRead $tag */
					$properties[] = new PropertyInfo(
						name:        '$' . (string) $tag->getVariableName(),
						type:        (string) $tag->getType(),
						description: (string) $tag->getDescription(),
						readOnly:    true,
					);
				}

			} catch (\Throwable) {}

			$examples = $this->extractExamples($rawComment);

			return [$summary, $description, $properties, $examples, $isInternal];

		}

		/**
		 * Parses public class constants from a list of class body statements.
		 *
		 * Only constants with a docblock are included — bare constants with no
		 * documentation are skipped as they provide no value in the reference output.
		 *
		 * @param  Node\Stmt[] $stmts
		 * @return ConstantInfo[]
		 */
		private function parseConstants(array $stmts): array {

			$constants = [];

			foreach ($stmts as $stmt) {

				if (!($stmt instanceof Node\Stmt\ClassConst)) {
					continue;
				}

				if (!($stmt->flags & Node\Stmt\Class_::MODIFIER_PUBLIC)) {
					continue;
				}

				$rawComment = $stmt->getDocComment()?->getText() ?? '';

				if ($rawComment === '') {
					continue;
				}

				$summary     = '';
				$description = '';
				$isInternal  = false;

				try {
					$docBlock    = $this->docBlockFactory->create($rawComment);
					$summary    = $docBlock->getSummary();
					$description = (string) $docBlock->getDescription();
					$isInternal  = count($docBlock->getTagsByName('internal')) > 0;
				} catch (\Throwable) {}

				if ($isInternal) {
					continue;
				}

				$examples = $this->extractExamples($rawComment);

				foreach ($stmt->consts as $const) {

					$value = match (true) {
						$const->value instanceof Node\Scalar\String_ => "'{$const->value->value}'",
						$const->value instanceof Node\Scalar\Int_    => (string) $const->value->value,
						$const->value instanceof Node\Scalar\Float_  => (string) $const->value->value,
						$const->value instanceof Node\Expr\ConstFetch => $const->value->name->toString(),
						default                                       => '',
					};

					$constants[] = new ConstantInfo(
						name:        $const->name->name,
						value:       $value,
						summary:     $summary,
						description: $description,
						examples:    $examples,
					);

				}

			}

			return $constants;

		}

		/**
		 * Parses public, non-internal methods from a list of class body statements.
		 *
		 * @param  Node\Stmt[] $stmts
		 * @return MethodInfo[]
		 */
		private function parseMethods(array $stmts): array {

			$methods = [];

			foreach ($stmts as $stmt) {

				if (!($stmt instanceof Node\Stmt\ClassMethod)) {
					continue;
				}

				if (!($stmt->flags & Node\Stmt\Class_::MODIFIER_PUBLIC)) {
					continue;
				}

				$rawComment        = $stmt->getDocComment()?->getText() ?? '';
				$summary           = '';
				$description       = '';
				$paramDescriptions = [];
				$returnType        = $stmt->returnType ? $this->typeToString($stmt->returnType) : 'void';
				$returnDescription = '';
				$throws            = [];
				$isInternal        = false;
				$examples          = [];

				if ($rawComment !== '') {

					try {

						$docBlock    = $this->docBlockFactory->create($rawComment);
						$summary    = $docBlock->getSummary();
						$description = (string) $docBlock->getDescription();
						$isInternal  = count($docBlock->getTagsByName('internal')) > 0;

						foreach ($docBlock->getTagsByName('param') as $tag) {
							/** @var Param $tag */
							$paramDescriptions[(string) $tag->getVariableName()] = (string) $tag->getDescription();
						}

						$returnTags = $docBlock->getTagsByName('return');

						if (!empty($returnTags)) {
							/** @var Return_ $returnTag */
							$returnTag = $returnTags[0];
							$tagType   = (string) $returnTag->getType();

							if ($tagType !== '') {
								$returnType = $tagType;
							}

							$returnDescription = (string) $returnTag->getDescription();
						}

						foreach ($docBlock->getTagsByName('throws') as $tag) {
							/** @var Throws $tag */
							$throws[] = new ThrowsInfo(
								type:        (string) $tag->getType(),
								description: (string) $tag->getDescription(),
							);
						}

					} catch (\Throwable) {}

					$examples = $this->extractExamples($rawComment);

				}

				if ($isInternal) {
					continue;
				}

				$params = [];

				foreach ($stmt->params as $param) {
					$varName = $param->var instanceof Node\Expr\Variable
						? (string) $param->var->name
						: '';

					$params[] = new ParamInfo(
						name:        '$' . $varName,
						type:        $param->type ? $this->typeToString($param->type) : 'mixed',
						description: $paramDescriptions[$varName] ?? '',
						optional:    $param->default !== null,
						variadic:    $param->variadic,
					);
				}

				$methods[] = new MethodInfo(
					name:              $stmt->name->name,
					summary:           $summary,
					description:       $description,
					params:            $params,
					returnType:        $returnType,
					returnDescription: $returnDescription,
					throws:            $throws,
					examples:          $examples,
					isStatic:          (bool) ($stmt->flags & Node\Stmt\Class_::MODIFIER_STATIC),
					isAbstract:        (bool) ($stmt->flags & Node\Stmt\Class_::MODIFIER_ABSTRACT),
				);

			}

			return $methods;

		}

		/**
		 * Extracts @example code blocks from a raw docblock comment string.
		 *
		 * Handles the SharkordPHP convention of embedding Markdown code fences
		 * directly beneath the @example tag, e.g.:
		 *
		 * ```
		 *  * @example
		 *  * ```php
		 *  * $sharkord->channels->get('general');
		 *  * ```
		 * ```
		 *
		 * @param  string   $rawComment Raw docblock text including delimiters.
		 * @return string[]             Trimmed example blocks, each including the ``` fences.
		 */
		private function extractExamples(string $rawComment): array {

			// Strip opening /** and closing */
			$inner = preg_replace('/^\s*\/\*\*\s*\n?/', '', $rawComment) ?? '';
			$inner = preg_replace('/\s*\*\/\s*$/', '', $inner) ?? '';

			// Remove leading whitespace + asterisk from each line: "	 * content" → "content"
			$lines = explode("\n", $inner);
			$clean = array_map(
				static fn(string $line): string => preg_replace('/^\s*\*\s?/', '', $line) ?? $line,
				$lines,
			);

			$cleanContent = implode("\n", $clean);

			// Capture everything between @example and the next @tag (or end of string).
			$examples = [];

			if (preg_match_all('/@example\s*\n(.*?)(?=\n@[a-zA-Z]|\z)/s', $cleanContent, $matches)) {
				foreach ($matches[1] as $block) {
					$trimmed = trim($block);
					if ($trimmed !== '') {
						$examples[] = $trimmed;
					}
				}
			}

			return $examples;

		}

		// -----------------------------------------------------------------------
		// Type resolution
		// -----------------------------------------------------------------------

		/**
		 * Converts a PHP-Parser type node to a human-readable string.
		 *
		 * @param  Node $type Any PHP-Parser type node.
		 * @return string
		 */
		private function typeToString(Node $type): string {

			return match (true) {
				$type instanceof Node\Identifier           => $type->name,
				$type instanceof Node\Name\FullyQualified  => '\\' . $type->toString(),
				$type instanceof Node\Name                 => $type->toString(),
				$type instanceof Node\NullableType         => '?' . $this->typeToString($type->type),
				$type instanceof Node\UnionType            => implode('|', array_map($this->typeToString(...), $type->types)),
				$type instanceof Node\IntersectionType     => implode('&', array_map($this->typeToString(...), $type->types)),
				default                                    => 'mixed',
			};

		}

		// -----------------------------------------------------------------------
		// Output
		// -----------------------------------------------------------------------

		/**
		 * Resolves the output subdirectory for a given namespace string.
		 *
		 * @param  string $namespace
		 * @return string            Subdirectory name, or empty string for the api/ root.
		 */
		private function namespaceToSubdir(string $namespace): string {

			foreach (self::NAMESPACE_DIR_MAP as $ns => $dir) {
				if ($namespace === $ns || str_starts_with($namespace, $ns . '\\')) {
					return $dir;
				}
			}

			return '';

		}

		/**
		 * Converts a PascalCase class name to a kebab-case filename slug.
		 *
		 * @param  string $name PascalCase class name (e.g. 'MessageManager').
		 * @return string       Kebab-case slug (e.g. 'message-manager').
		 */
		private function toKebabCase(string $name): string {

			return strtolower(
				ltrim(preg_replace('/([A-Z])/', '-$1', $name) ?? $name, '-')
			);

		}

		/**
		 * Writes the Markdown documentation file for the given ClassInfo.
		 *
		 * @param  ClassInfo $info
		 * @return void
		 */
		private function writeDoc(ClassInfo $info): void {

			$subdir  = $this->namespaceToSubdir($info->namespace);
			$outDir  = $subdir !== '' ? "{$this->outPath}/{$subdir}" : $this->outPath;
			$slug    = $this->toKebabCase($info->name);
			$outFile = "{$outDir}/{$slug}.md";

			if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
				$this->log("  [ERROR] Could not create directory: {$outDir}");
				return;
			}

			$content = $this->renderMarkdown($info);

			if (file_put_contents($outFile, $content) === false) {
				$this->log("  [ERROR] Could not write: {$outFile}");
				return;
			}

			$relative = str_replace("{$this->outPath}/", '', $outFile);
			$this->log("  ✓  {$info->fqcn()}  →  api/{$relative}");

		}

		/**
		 * Renders a ClassInfo object as a complete Starlight-compatible Markdown string.
		 *
		 * @param  ClassInfo $info
		 * @return string
		 */
		private function renderMarkdown(ClassInfo $info): string {

			$lines = [];

			// --- Frontmatter ---
			$descriptionEscaped = str_replace('"', '\\"', $info->summary ?: $info->name);
			$typeLabel          = match ($info->type) {
				'enum'      => 'Enum',
				'interface' => 'Interface',
				default     => 'Class',
			};

			$lines[] = '---';
			$lines[] = "title: {$info->name}";
			$lines[] = "description: \"{$descriptionEscaped}\"";
			$lines[] = '---';
			$lines[] = '';

			// --- Type badge + FQCN ---
			$lines[] = "**{$typeLabel}** &nbsp;·&nbsp; `{$info->fqcn()}`";
			$lines[] = '';

			// --- Summary ---
			if ($info->summary !== '') {
				$lines[] = $info->summary;
				$lines[] = '';
			}

			// --- Extended description ---
			if ($info->description !== '') {
				$lines[] = $info->description;
				$lines[] = '';
			}

			// --- Class-level examples ---
			if (!empty($info->examples)) {
				$lines[] = '## Examples';
				$lines[] = '';
				foreach ($info->examples as $example) {
					$lines[] = $this->ensureCodeFence($example);
					$lines[] = '';
				}
			}

			// --- Enum cases ---
			if (!empty($info->enumCases)) {
				$lines[] = '## Cases';
				$lines[] = '';
				$lines[] = '| Case | Value |';
				$lines[] = '|---|---|';
				foreach ($info->enumCases as $case) {
					[$caseName, $caseValue] = array_pad(explode(' = ', $case, 2), 2, '—');
					$lines[] = "| `{$caseName}` | `{$caseValue}` |";
				}
				$lines[] = '';
			}

			// --- Properties ---
			if (!empty($info->properties)) {
				$lines[] = '## Properties';
				$lines[] = '';
				$lines[] = '| Property | Type | Description |';
				$lines[] = '|---|---|---|';
				foreach ($info->properties as $prop) {
					$badge  = $prop->readOnly ? ' *(read-only)*' : '';
					$desc   = str_replace(["\n", '|'], [' ', '\\|'], $prop->description);
					$lines[] = "| `{$prop->name}`{$badge} | `{$prop->type}` | {$desc} |";
				}
				$lines[] = '';
			}

			// --- Constants ---
			if (!empty($info->constants)) {
				$lines[] = '## Constants';
				$lines[] = '';

				foreach ($info->constants as $const) {

					$lines[] = "### `{$const->name}`";
					$lines[] = '';

					if ($const->value !== '') {
						$lines[] = "**Value** `{$const->value}`";
						$lines[] = '';
					}

					if ($const->summary !== '') {
						$lines[] = $const->summary;
						$lines[] = '';
					}

					if ($const->description !== '') {
						$lines[] = $const->description;
						$lines[] = '';
					}

					if (!empty($const->examples)) {
						$lines[] = '**Example**';
						$lines[] = '';
						foreach ($const->examples as $example) {
							$lines[] = $this->ensureCodeFence($example);
							$lines[] = '';
						}
					}

					$lines[] = '---';
					$lines[] = '';

				}
			}

			// --- Methods ---
			if (!empty($info->methods)) {
				$lines[] = '## Methods';
				$lines[] = '';

				foreach ($info->methods as $method) {

					$prefix  = $method->isStatic ? 'static ' : '';
					$lines[] = "### `{$prefix}{$method->name}()`";
					$lines[] = '';

					if ($method->summary !== '') {
						$lines[] = $method->summary;
						$lines[] = '';
					}

					if ($method->description !== '') {
						$lines[] = $method->description;
						$lines[] = '';
					}

					if (!empty($method->params)) {
						$lines[] = '**Parameters**';
						$lines[] = '';
						$lines[] = '| Parameter | Type | Description |';
						$lines[] = '|---|---|---|';
						foreach ($method->params as $param) {
							$prefix   = $param->variadic ? '...' : '';
							$suffix   = $param->optional ? ' *(optional)*' : '';
							$desc     = str_replace(["\n", '|'], [' ', '\\|'], $param->description);
							$lines[]  = "| `{$prefix}{$param->name}`{$suffix} | `{$param->type}` | {$desc} |";
						}
						$lines[] = '';
					}

					if ($method->returnType !== 'void') {
						$returnDesc = $method->returnDescription !== ''
							? " — {$method->returnDescription}"
							: '';
						$lines[] = "**Returns** `{$method->returnType}`{$returnDesc}";
						$lines[] = '';
					}

					if (!empty($method->throws)) {
						$lines[] = '**Throws**';
						$lines[] = '';
						foreach ($method->throws as $throws) {
							$desc    = $throws->description !== '' ? " — {$throws->description}" : '';
							$lines[] = "- `{$throws->type}`{$desc}";
						}
						$lines[] = '';
					}

					if (!empty($method->examples)) {
						$lines[] = '**Example**';
						$lines[] = '';
						foreach ($method->examples as $example) {
							$lines[] = $this->ensureCodeFence($example);
							$lines[] = '';
						}
					}

					$lines[] = '---';
					$lines[] = '';

				}

			}

			return implode("\n", $lines);

		}

		/**
		 * Ensures the given example string is wrapped in a PHP code fence.
		 * If the block already contains ``` markers they are left untouched.
		 *
		 * @param  string $example Raw example content.
		 * @return string          Content wrapped in ```php ... ``` if needed.
		 */
		private function ensureCodeFence(string $example): string {

			if (str_starts_with(ltrim($example), '```')) {
				return $example;
			}

			return "```php\n{$example}\n```";

		}

		/**
		 * Writes a line to STDOUT.
		 *
		 * @param  string $message
		 * @return void
		 */
		private function log(string $message): void {

			echo $message . PHP_EOL;

		}

	}

	// ---------------------------------------------------------------------------
	// Entry point
	// ---------------------------------------------------------------------------

	$opts = getopt('', ['src:', 'out:']);

	$srcPath = isset($opts['src'])
		? rtrim((string) $opts['src'], '/')
		: dirname(__DIR__) . '/SharkordPHP/src';

	$outPath = isset($opts['out'])
		? rtrim((string) $opts['out'], '/')
		: dirname(__DIR__) . '/src/content/docs/api';

	if (!is_dir($srcPath)) {
		fwrite(STDERR, "Error: Source directory does not exist: {$srcPath}\n");
		exit(1);
	}

	echo "SharkordPHP API Doc Generator\n";
	echo str_repeat('─', 50) . "\n";
	echo "  Source : {$srcPath}\n";
	echo "  Output : {$outPath}\n\n";

	(new ApiGenerator($srcPath, $outPath))->run();

	echo "\nDone.\n";

?>