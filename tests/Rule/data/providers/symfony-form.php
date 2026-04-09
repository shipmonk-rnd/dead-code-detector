<?php declare(strict_types = 1);

namespace SymfonyForm;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Attribute\Route;

// Case 1: configureOptions with setDefaults

class BaseProductData {

    public function getInherited(): string {
        return '';
    }
}

class ProductData extends BaseProductData {

    public function __construct() {
    }

    public string $name;

    private string $description;

    private int $price;

    private bool $active;

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function getDescription(): string {
        return $this->description;
    }

    public function setDescription(string $description): void {
        $this->description = $description;
    }

    public function getPrice(): int {
        return $this->price;
    }

    public function setPrice(int $price): void {
        $this->price = $price;
    }

    public function isActive(): bool {
        return $this->active;
    }

    public function setActive(bool $active): void {
        $this->active = $active;
    }

    public function hasExpired(): bool {
        return false;
    }

    private function getSecret(): string { // error: Unused SymfonyForm\ProductData::getSecret
        return '';
    }

    public static function staticFactory(): self { // error: Unused SymfonyForm\ProductData::staticFactory
        return new self();
    }

    public function dead(): void { // error: Unused SymfonyForm\ProductData::dead
    }
}

class ProductType extends AbstractType {

    public function buildForm(FormBuilderInterface $builder, array $options): void {
        $builder
            ->add('name', TextType::class)
            ->add('price', NumberType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => ProductData::class,
        ]);
    }
}

// Case 1b: configureOptions with setDefault

class CategoryData {

    private string $title;

    public function getTitle(): string {
        return $this->title;
    }

    public function setTitle(string $title): void {
        $this->title = $title;
    }

    public function unused(): void { // error: Unused SymfonyForm\CategoryData::unused
    }
}

class CategoryType extends AbstractType {

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefault('data_class', CategoryData::class);
    }
}

// Case 1c: public property data class

class PublicPropertyData {

    public string $label;

    public function dead(): void { // error: Unused SymfonyForm\PublicPropertyData::dead
    }
}

class PublicPropertyFormType extends AbstractType {

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'data_class' => PublicPropertyData::class,
        ]);
    }
}

// Case 1d: form without data_class

class NoDataClassType extends AbstractType {

    public function configureOptions(OptionsResolver $resolver): void {
        $resolver->setDefaults([
            'csrf_protection' => false,
        ]);
    }
}

// Case 2: createForm with explicit data_class in options

class InlineData {

    private string $value;

    public function getValue(): string {
        return $this->value;
    }

    public function setValue(string $value): void {
        $this->value = $value;
    }

    public function dead(): void { // error: Unused SymfonyForm\InlineData::dead
    }
}

class InlineDataController extends AbstractController {

    #[Route('/inline')]
    public function action(): Response {
        $this->createForm(ProductType::class, null, [
            'data_class' => InlineData::class,
        ]);

        return new Response();
    }
}

// Case 3: createForm with typed data object

class ImplicitData {

    private string $label;

    public function getLabel(): string {
        return $this->label;
    }

    public function setLabel(string $label): void {
        $this->label = $label;
    }

    public function dead(): void { // error: Unused SymfonyForm\ImplicitData::dead
    }
}

class ImplicitDataController extends AbstractController {

    #[Route('/implicit')]
    public function action(): Response {
        $data = new ImplicitData();
        $this->createForm(ProductType::class, $data);

        return new Response();
    }
}

// Case 4: createFormBuilder with typed data object

class BuilderData {

    private string $name;

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): void {
        $this->name = $name;
    }

    public function dead(): void { // error: Unused SymfonyForm\BuilderData::dead
    }
}

class BuilderDataController extends AbstractController {

    #[Route('/builder')]
    public function action(): Response {
        $data = new BuilderData();
        $this->createFormBuilder($data);

        return new Response();
    }
}

// Case 5: FormFactoryInterface::create with typed data object

class FactoryData {

    private string $title;

    public function getTitle(): string {
        return $this->title;
    }

    public function setTitle(string $title): void {
        $this->title = $title;
    }

    public function dead(): void { // error: Unused SymfonyForm\FactoryData::dead
    }
}

class FactoryService {

    public function __construct( // error: Unused SymfonyForm\FactoryService::__construct
        private readonly FormFactoryInterface $formFactory, // error: Property SymfonyForm\FactoryService::$formFactory is never read // error: Property SymfonyForm\FactoryService::$formFactory is never written
    ) {
    }

    public function doSomething(): void { // error: Unused SymfonyForm\FactoryService::doSomething
        $data = new FactoryData();
        $this->formFactory->create(ProductType::class, $data);
    }
}
