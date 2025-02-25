window.initializeChatUI = function() {
    let chatOverlay = document.getElementById("chatbot-overlay");
    if (!chatOverlay) {
        chatOverlay = document.createElement("div");
        chatOverlay.id = "chatbot-overlay";
        chatOverlay.style.display = "none";
        document.body.appendChild(chatOverlay);
    }

    let chatModal = document.getElementById("chatbot-modal");
    if (!chatModal) {
        chatModal = document.createElement("div");
        chatModal.id = "chatbot-modal";
        chatModal.innerHTML = `
            <div id="chatbot-container">
                <div id="chatbot-header">
                    <h2>Welcome to Order Assistant</h2>
                    <button id="close-chatbot">&times;</button>
                </div>
                <div id="chatbot-body">
                    <div id="chatbot-messages"></div>
                    <textarea id="chatbot-input" placeholder="Type your message..." rows="3" style="width: 100%;"></textarea>
                    <button id="chatbot-send">Send</button>
                    <button id="reset-order" class="chatbot-reset-button">Reset Order</button>
                    <button onclick="createWooCommerceCart()" class="create-cart-button">Create Order</button>
                </div>
            </div>
        `;
        document.body.appendChild(chatModal);
    }

    let chatToggleButton = document.getElementById("chatbot-toggle");
    if (!chatToggleButton) {
        chatToggleButton = document.createElement("button");
        chatToggleButton.id = "chatbot-toggle";
        chatToggleButton.innerText = "Use Order Assistant";
        chatToggleButton.className = "chatbot-toggle-button";
        document.body.appendChild(chatToggleButton);
    }

    chatToggleButton.addEventListener("click", function() {
        chatOverlay.style.display = "block";
        chatModal.style.display = "flex";
    });

    document.getElementById("close-chatbot").addEventListener("click", function() {
        chatOverlay.style.display = "none";
        chatModal.style.display = "none";
    });

    document.getElementById("reset-order").addEventListener("click", function() {
        sessionStorage.removeItem("orderType");
        location.reload();
    });

    // Attach event listener for sending messages
    document.getElementById("chatbot-send").addEventListener("click", function () {
        sendUserMessage();
    });

    document.getElementById("chatbot-input").addEventListener("keypress", function (event) {
        if (event.key === "Enter") {
            event.preventDefault();
            sendUserMessage();
        }
    });
};

// Function to send user input to the GPT request handler
function sendUserMessage() {
    const userInputField = document.getElementById("chatbot-input");
    const userMessage = userInputField.value.trim();

    if (!userMessage) return;

    displayMessage("You: " + userMessage, "user");
    userInputField.value = "";

    fetchGPTResponse(userMessage);
}

// Ensure the chat UI is initialized before anything else
document.addEventListener("DOMContentLoaded", function() {
    initializeChatUI();

    if (!sessionStorage.getItem("orderType")) {
        displayMessage("CR8: Are you ordering for an Agent or a Property?", "gpt", true);
    } else {
        displayMessage(`CR8: (Order Type: ${sessionStorage.getItem("orderType")})`, "gpt");
    }
});

window.displayMessage = function(text, sender, isSelection = false) {
    let messageContainer = document.getElementById("chatbot-messages");

    if (!messageContainer) {
        console.error("Error: 'chatbot-messages' container not found.");
        return;
    }

    const messageElement = document.createElement("div");
    messageElement.className = sender;

    // Convert line breaks from GPT (\n) into HTML <br> tags
    messageElement.innerHTML = text.replace(/\n/g, "<br>");

    if (isSelection) {
        const selectionContainer = document.createElement("div");
        selectionContainer.id = "chatbot-selection-container";

        const agentButton = document.createElement("button");
        agentButton.innerText = "Agent";
        agentButton.className = "chatbot-selection-button";
        agentButton.onclick = function() {
            selectUserType("Agent");
        };

        const propertyButton = document.createElement("button");
        propertyButton.innerText = "Property";
        propertyButton.className = "chatbot-selection-button";
        propertyButton.onclick = function() {
            selectUserType("Property");
        };

        selectionContainer.appendChild(agentButton);
        selectionContainer.appendChild(propertyButton);
        messageElement.appendChild(selectionContainer);
    }

    messageContainer.appendChild(messageElement);
    messageContainer.scrollTop = messageContainer.scrollHeight;
};

// Function to handle user selection
window.selectUserType = function(selection) {
    sessionStorage.setItem("orderType", selection);

    const selectionContainer = document.getElementById("chatbot-selection-container");
    if (selectionContainer) {
        selectionContainer.style.display = "none";
    }

    displayMessage(`GPT: (Order Type: ${selection})`, "gpt");
    fetchWooCommerceProducts(selection);
};

// Single source of truth for fetching WooCommerce products
function fetchWooCommerceProducts(selection) {
    // Show immediate feedback to user
    displayMessage("CR8: Initializing product catalog...", "gpt");

    fetch(chatbot_ajax.ajax_url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
            action: "fetch_woocommerce_products",
            order_type: selection
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log("WooCommerce Products Received:", data);

        if (data.success && data.data.products) {
            let formattedProducts = JSON.stringify(data.data.products, null, 2);
            
            // Phase 2: Initialize GPT context
            fetch(chatbot_ajax.ajax_url, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({
                    action: "initialize_gpt_context",
                    products: formattedProducts,
                    order_type: selection
                })
            })
            .then(response => response.json())
            .then(result => {
                console.log("GPT Context Initialization:", result);
                if (result.success) {
                    displayMessage("CR8: Great! Let's put together an order for " + selection + " marketing.", "gpt");
                }
            })
            .catch(error => {
                console.error("Error initializing GPT context:", error);
            });
        } else {
            displayMessage("GPT: Error retrieving product data.", "error");
        }
    })
    .catch(error => {
        console.error("Error:", error);
        displayMessage("GPT: Unable to fetch product data.", "error");
    });
}

// Function to send AJAX request to WordPress backend for GPT response
function fetchGPTResponse(userMessage) {
    if (typeof chatbot_ajax === "undefined" || !chatbot_ajax.ajax_url) {
        console.error("Error: chatbot_ajax is not defined. Make sure wp_localize_script() is properly added in PHP.");
        displayMessage("GPT: Error connecting to server.", "error");
        return;
    }

    fetch(chatbot_ajax.ajax_url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
            action: "fetch_gpt_response",
            user_input: userMessage
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log("GPT Response Data:", data);

        // Fix error message condition
        if (data.success && data.data && data.data.response) {
            displayMessage("GPT: " + data.data.response, "gpt");
        } else {
            displayMessage("GPT: Error retrieving response.", "error");
            console.error("GPT Error:", data);
        }
    })
    .catch(error => {
        console.error("Error:", error);
        displayMessage("GPT: Unable to connect.", "error");
    });
}

function createWooCommerceCart() {
    fetch(chatbot_ajax.ajax_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'create_gpt_cart',
            nonce: chatbot_ajax.nonce
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success && data.data.redirect_url) {
            // Show success message and redirect
            alert(data.data.message);
            window.location.href = data.data.redirect_url;
        } else {
            // Show error message
            alert(data.data.message || 'Failed to create cart');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to create cart');
    });
}
