<?php

namespace app\Livewire;

use Livewire\Component;
use App\Models\Product;
use App\Models\Category;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProductsList extends Component
{
    use WithFileUploads;
    
    public $products = [];
    public $categories = [];
    public $lastUpdated;
    public $status = 'Loading products...';
    public $refreshInterval = 10; // in seconds
    
    public $sortField = 'name';
    public $sortDirection = 'asc';

    // Form properties
    public $showProductModal = false;
    public $editMode = false;
    public $productId = null;
    
    #[Rule('required|min:3|max:255')]
    public $name = '';
    
    #[Rule('required|numeric|min:0.01')]
    public $price = '';
    
    public $iconType = 'bootstrap';
    
    #[Rule('required_if:iconType,bootstrap')]
    public $bootstrapIcon = 'bi-box';
    
    #[Rule('nullable|file|max:1024|required_if:iconType,svg')]
    public $svgFile;
    
    #[Rule('nullable|exists:categories,id')]
    public $categoryId;

    public $iconValue = '';
    public $categoryName = '';
    public $description = '';
    #[Rule('nullable|file|max:1024')]
    public $photoFile;
    public $photo = '';
    
    // Protected listeners for Livewire events
    protected $listeners = ['deleteConfirmed' => 'deleteConfirmed', 'categoryAdded' => 'loadCategories'];

    public function mount()
    {
        $this->loadProducts();
        $this->loadCategories();
    }

    public function sortBy($field)
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'asc';
        }

        $this->loadProducts();
    }

    public function loadProducts()
    {
        $user = Auth::user();
        if ($user->is_admin) {
            $this->products = Product::with('category')
                ->orderBy($this->sortField, $this->sortDirection)
                ->get();
        } else if ($user->is_editor) {
            $this->products = Product::with('category')
                ->where('editor_id', $user->id)
                ->orderBy($this->sortField, $this->sortDirection)
                ->get();
        } else {
            $this->products = collect();
        }
        $this->lastUpdated = now()->format('H:i:s');
        $this->status = 'Products updated at ' . $this->lastUpdated;
    }

    public function loadCategories()
    {
        $user = Auth::user();
        if ($user->is_admin) {
            $this->categories = Category::orderBy('name')->get();
        } else if ($user->is_editor) {
            $this->categories = Category::where('editor_id', $user->id)->orderBy('name')->get();
        } else {
            $this->categories = collect();
        }
        
        // Log category scoping
        \Log::info('Categories loaded for user ' . $user->id . ' (admin=' . ($user->is_admin ? '1' : '0') . ', editor=' . ($user->is_editor ? '1' : '0') . '): ' . $this->categories->pluck('id')->join(','));
    }

    #[On('refresh-products')]
    public function refreshProducts()
    {
        $this->loadProducts();
    }
    
    // Create or update a product
    public function saveProduct()
    {
        $user = Auth::user();
        if ($this->iconType === 'bootstrap') {
            $this->validate([
                'name' => 'required|min:3|max:255',
                'price' => 'required|numeric|min:0.01',
                'bootstrapIcon' => 'required',
            ]);
            $iconValue = $this->bootstrapIcon;
        } else {
            $this->validate([
                'name' => 'required|min:3|max:255',
                'price' => 'required|numeric|min:0.01',
                'svgFile' => 'nullable|file|max:1024',
            ]);

            if ($this->svgFile) {
                // Store the new SVG file and get its filename
                $filename = $this->svgFile->store('product-icons', 'public');
                $iconValue = $filename;
            } else {
                // Retain the existing icon value if no new file is uploaded
                $iconValue = $this->iconValue;
            }
        }
        
        // Handle photo upload
        if ($this->photoFile) {
            $photoPath = $this->photoFile->store('product-photos', 'public');
        } else {
            $photoPath = $this->photo;
        }

        if ($this->editMode) {
            $product = Product::findOrFail($this->productId);
            if (!$user->is_admin && !($user->is_editor && $product->editor_id == $user->id)) {
                abort(403);
            }
            $product->update([
                'name' => $this->name,
                'price' => $this->price,
                'icon_type' => $this->iconType,
                'icon_value' => $iconValue,
                'category_id' => $this->categoryId,
                'description' => $this->description,
                'photo' => $photoPath,
            ]);
            $this->status = "Product '{$this->name}' updated successfully!";
        } else {
            $data = [
                'name' => $this->name,
                'price' => $this->price,
                'icon_type' => $this->iconType,
                'icon_value' => $iconValue,
                'category_id' => $this->categoryId,
                'description' => $this->description,
                'photo' => $photoPath,
            ];
            if ($user->is_admin) {
                $data['editor_id'] = $user->id; // Optionally allow admin to set another editor_id if needed
            } else {
                $data['editor_id'] = $user->id;
            }
            Product::create($data);
            $this->status = "Product '{$this->name}' added successfully!";
        }
        
        $this->closeModal();
        $this->loadProducts();
    }
    
    public function saveCategory()
    {
        $user = Auth::user();
        $this->validate([
            'categoryName' => [
                'required',
                'min:3',
                'max:255',
                Rule::unique('categories', 'name')->where(function ($query) use ($user) {
                    return $query->where('editor_id', $user->id);
                }),
            ],
        ]);

        Category::create([
            'name' => $this->categoryName,
            'editor_id' => $user->id,
        ]);
        $this->categoryName = '';
        $this->loadCategories();
        $this->status = 'Category added successfully!';
    }

    public function deleteCategory($id)
    {
        $category = Category::find($id);

        if (!$category) {
            $this->status = 'Error: Category not found';
            return;
        }

        $categoryName = $category->name;
        $category->delete();
        $this->loadCategories();
        $this->status = "Category '{$categoryName}' deleted successfully!";
    }
    
    // Show modal for adding a new product
    public function addProduct()
    {
        $this->resetForm();
        $this->editMode = false;
        $this->showProductModal = true;
    }
    
    // Show modal for editing a product
    public function editProduct($id)
    {
        $this->resetForm();
        $this->editMode = true;
        $this->productId = $id;
        
        $product = Product::findOrFail($id);
        $this->name = $product->name;
        $this->price = $product->price;
        $this->iconType = $product->icon_type ?? 'bootstrap';
        
        if ($this->iconType === 'bootstrap') {
            $this->bootstrapIcon = $product->icon_value ?? 'bi-box';
        } else {
            $this->iconValue = $product->icon_value;
        }
        
        $this->categoryId = $product->category_id;
        $this->description = $product->description;
        $this->photo = $product->photo;
        $this->showProductModal = true;
    }
    
    // Show delete confirmation dialog
    public function confirmDelete($id)
    {
        $product = Product::findOrFail($id);
        $this->productId = $id;
        
        $this->dispatch('showDeleteConfirmation', [
            'title' => 'Delete Product',
            'message' => "Are you sure you want to delete the product '{$product->name}'?",
            'confirmButtonText' => 'Delete',
            'eraseAll' => false
        ]);
    }
    
    // Delete a product when called directly (from the button)
    public function deleteProduct($id = null)
    {
        $user = Auth::user();
        $productId = $id ?? $this->productId;
        
        if (!$productId) {
            $this->status = "Error: No product selected for deletion";
            return;
        }
        
        $product = Product::find($productId);
        
        if (!$product) {
            $this->status = "Error: Product not found";
            return;
        }
        
        if (!$user->is_admin && !($user->is_editor && $product->editor_id == $user->id)) {
            abort(403);
        }
        
        $productName = $product->name;
        $product->delete();
        
        $this->productId = null;
        $this->status = "Product '{$productName}' deleted successfully!";
        $this->loadProducts();
    }
    
    // Delete a product after confirmation
    public function deleteConfirmed()
    {
        $this->deleteProduct();
    }
    
    // Toggle icon type between bootstrap and svg
    public function toggleIconType()
    {
        $this->iconType = $this->iconType === 'bootstrap' ? 'svg' : 'bootstrap';
    }
    
    // Close the modal and reset form
    public function closeModal()
    {
        $this->showProductModal = false;
        $this->resetForm();
    }
    
    // Reset form fields
    private function resetForm()
    {
        $this->productId = null;
        $this->name = '';
        $this->price = '';
        $this->iconType = 'bootstrap';
        $this->bootstrapIcon = 'bi-box';
        $this->svgFile = null;
        $this->iconValue = '';
        $this->categoryId = null;
        $this->description = '';
        $this->photoFile = null;
        $this->photo = '';
        $this->resetValidation();
    }

    public function eraseAllProducts()
    {
        $user = Auth::user();
        if ($user->is_admin) {
            $count = Product::count();
            Product::truncate();
        } else if ($user->is_editor) {
            $count = Product::where('editor_id', $user->id)->count();
            Product::where('editor_id', $user->id)->delete();
        } else {
            $count = 0;
        }
        $this->status = $count . ' products deleted.';
        $this->loadProducts();
    }

    public function confirmEraseAll()
    {
        $user = Auth::user();
        $count = Product::where('editor_id', $user->id)->count();
        $this->dispatch('showDeleteConfirmation', [
            'message' => "Are you sure you want to erase ALL (" . $count . ") products? This action cannot be undone.",
            'eraseAll' => 'true'
        ]);
    }

    public function deleteAllConfirmed()
    {
        $user = Auth::user();
        $count = Product::where('editor_id', $user->id)->count();
        Product::where('editor_id', $user->id)->delete();
        $this->status = $count . ' products deleted.';
        $this->loadProducts();
    }

    public function render()
    {
        $user = Auth::user();
        if ($user->is_admin) {
            $products = Product::with('category')->get();
        } else if ($user->is_editor) {
            $products = Product::with('category')->where('editor_id', $user->id)->get();
        } else {
            $products = collect();
        }
        return view('livewire.products-list', [
            'products' => $products,
        ]);
    }
}