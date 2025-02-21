let userSelection = sessionStorage.getItem("orderType") || null; // Retrieve stored selection

function askUserOrderType() {
    if (!userSelection) {
        displayMessage("GPT: Are you ordering for an Agent or a Property?", "gpt", true);
    } else {
        displayMessage(`GPT: (Order Type: ${userSelection})`, "gpt");
        fetchWooCommerceProducts(); // Fetch products immediately after selection
    }
}

// Function to handle user selection
window.selectUserType = function(selection) {
    userSelection = selection;
    sessionStorage.setItem("orderType", selection); // Store selection persistently

    // Hide buttons after selection
    const selectionContainer = document.getElementById("chatbot-selection-container");
    if (selectionContainer) {
        selectionContainer.style.display = "none";
    }

    // Display the order type
    displayMessage(`GPT: (Order Type: ${selection})`, "gpt");

    // Fetch relevant products immediately
    fetchWooCommerceProducts();
};

// Function to fetch WooCommerce products for the selected user type
function fetchWooCommerceProducts() {
    if (!userSelection) {
        console.error("Error: User selection is missing.");
        return;
    }

    displayMessage(`GPT: Fetching available ${userSelection} products...`, "gpt");

    const authHeader = {
        "Authorization": "Basic " + btoa("ck_c96a29f12777a7b95aea92f180ae4c52250ed218:cs_0c8095127920e081efa0a092e336b05772569911"),
        "Content-Type": "application/json"
    };

    Promise.all([
        fetch("/wp-json/wc/v3/products/categories?per_page=100", { method: "GET", headers: authHeader }).then(response => response.json()),
        fetch("/wp-json/wc/v3/products?per_page=100", { method: "GET", headers: authHeader }).then(response => response.json())
    ])
    .then(([categories, products]) => {
        let filteredProducts = { products: [] };

        products.forEach(product => {
            let productCategories = product.categories.map(cat => cat.name);
            let productTags = product.tags ? product.tags.map(tag => tag.name.toLowerCase()) : [];

            if (productTags.includes(userSelection.toLowerCase())) { // Ensure case-insensitive match
                filteredProducts.products.push({
                    name: product.name,
                    price: `$${product.price}`,
                    categories: productCategories,
                    tags: productTags
                });
            }
        });

        // Send product data to WordPress for storage
        fetch(chatbot_ajax.ajax_url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({
                action: "store_woocommerce_products",
                products: JSON.stringify(filteredProducts)
            })
        });

        displayMessage("GPT: Here is the structured data:\n" + JSON.stringify(filteredProducts, null, 2), "gpt");
    })
    .catch(error => {
        console.error("Error fetching products or categories:", error);
        displayMessage("GPT: Sorry, I cannot retrieve product information.", "gpt");
    });
}