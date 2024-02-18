# Telegram Bot AI App

## About the Project

The Telegram Bot AI App, or Telebot AI, connects your Telegram chat to an AI model, enabling seamless communication and interaction. This bot supports various AI APIs, including Ollama, OpenAI, and Mistral, offering flexibility and customization options to suit your preferences. Whether you're using it on mobile, desktop, or web, Telebot AI can be integrated into group chats or private conversations, enhancing communication with its advanced AI capabilities.

### Key Features:

- Connects Telegram chat to AI models
- Supports Ollama, OpenAI, and Mistral APIs
- Works on multiple platforms
- Easy setup and customization
- Basic settings and debug features included

Telebot AI leverages the GPT-3.5-turbo-0125 model for optimal results. With OpenAI, users receive $5 for API calls, offering cost-effective AI integration. Additionally, Ollama provides the advantage of running uncensored models locally, giving users full control over their AI experience. The script is designed for easy API and model switching, allowing users to experiment and find the best fit for their needs.

This version focuses on basic Telegram-AI integration. Different versions could aim to expand functionality to include features like personal assistant capabilities, audio and image processing, integration with home assistants to control smart homes, weather forecasts, and calendar or note management.

## Installation Guide

### Prerequisites:

- Install Apache2, PHP, and PHP-SQLite3 on your server.
  - For Ubuntu:
    ```
    sudo apt update
    sudo apt install apache2 php php-sqlite3 php-curl
    ```
  - If you plan to use a webhook, you will need to enable HTTPS and get an SSL certificate. Certbot is the easiest way to do that.
  - Test if the web server is working before moving on to the next steps.

### Setup Instructions:

1. **Create Telegram Bot:**
   - Use the BotFather in Telegram to create a new bot and obtain the bot token.
   - Open Telegram and search for the BotFather.
   - Follow the instructions to create a new bot and obtain the token.

2. **Choose AI Setup:**
   - **OpenAI:**
     - Sign up for an OpenAI account to obtain the API token on platform.openai.com.
     - Recommended model: GPT-3.5-turbo-0125 
   - **Ollama:**
     - Install Ollama and select a model according to your preferences.
       ```
       curl -fsSL https://ollama.com/install.sh | sh
       ```
     - After installing Ollama, select a model according to your preference. The list of models can be found here: https://ollama.com/library
     - Recommended mistral: `ollama run mistral`
   - **Mistral:**
     - Sign up for a Mistral account to obtain the API token.

3. **Edit Configuration:**
   - Navigate to the web root directory containing the Telegram bot files.
   - Open `index.php` and fill in the configuration tokens and settings based on your choices.
   - Save and close the file.

4. **Set Up Telegram Messaging:**
   - Use a webhook to receive messages from Telegram.
     - Replace `<YOUR_BOT_TOKEN>` and the URL to the Telegram script with your actual bot token and domain.
       ```
       curl -X POST https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook -d "url=https://your-new-domain.com/your-webhook-path"
       curl -X POST https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo
       ```
   - Set up a cron job to run the script every minute to receive and send messages.
     ```
     0/1 * * * * php /path/to/index.php
     ```

5. **Testing:**
   - Try sending a message to the bot from your mobile. 
   - If you do not receive a reply message:
     - Check if the database file is created. If not, check the permissions of the folder and possibly set ownership (`sudo chown www-data:www-data /var/www/html`). It is not recommended to set permissions to 777, but if you still struggle, then try it cautiously.
     - Check the Apache2 log file `/var/log/apache2/error.log` for errors.
     - Try opening `https://your-new-domain.com/your-webhook-path/index.php` in a browser - you should see "OK" on the page.
     - Edit the `index.php` file and scroll to the very bottom, and uncomment debug code. Save and close the file, then run `php index.php` from the terminal to see debug messages. When finished comment it back.

## Possible Errors and Solutions

- Ensure correct file and folder permissions to prevent write errors. The web server user (www-data) should own the folder and file.
- When configuring the web server, set file access to prevent the .db file from being accessible from the internet. The SQLite3 file contains all conversation history and is not encrypted!
- When using a slower model, the database is sometimes locked because another instance of the script is still running. Processing of new messages can be affected as the second script can't save them to the database.

## Conclusion

The script was tested on Ubuntu 22.04 LTS with a public IP address and webhook set up. 
It can be used and modified by anybody for any reason. 
Hope someone finds it useful.
