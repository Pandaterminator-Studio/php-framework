# 🌈 PHP-Framework "Iris"
## _A simple, fast, and easy-to-use framework_

Iris is a PHP-Framework created to make building websites easy, without requiring extensive knowledge or the need to learn a complex new PHP-Framework.

✨ **Key Features:**
- 🚀 Fast & Easy to set up
- 🧩 Just implement your controllers, views, and models
- 🔗 Link pre-built functions to your controllers

## 🛠️ Features

- 📝 Error logging system, local or in database
- ⚙️ Easy to enable functions via config file
- 🧭 Built-in page router (check wiki for editing instructions)
- 🔒 Built-in hashing classes and other security functions
- 🚨 Advanced error handler
- 🗃️ Built-in PDO style database handler
- 🎨 Built-in Twig view handler for designing your application layout with HTML
- 🤖 Built-in AI functions (OpenAI & Claude AI)
- 🔮 More to come...

## 📚 Wiki

### Views, Controllers & Models
Iris uses Twig to handle views in your application, allowing you to use your own HTML.

To get started, edit the 'base.html' file in the Views folder. This will be the base of your application.

#### What you need to include in your base.html

To change the title of the window when navigating pages, use the following title tag in your HTML head:

```html
<title>{% block title %}{% endblock %}</title>
```

In the body tag, include the following code (recommended to put it after the navigation bar and before any JS scripts):

```html
{% block body %}{% endblock %}
```

#### Creating a View

1. Create a folder named exactly like your website page (e.g., 'Home') in the Views folder.
2. Add an index.html file to this folder with the following code:

```html
{% extends "base.html" %}
{% block title %} Dashboard {% endblock %}
{% block body %}
<p>I'm an HTML view</p>
{% endblock %}
```

#### Creating a Controller

Create a 'Home.php' file in the Controller folder with the following structure:

```php
<?php
namespace App\Controllers;

use \Core\View;

class Home extends \Core\Controller {
    protected function before() {
        return false;
    }

    protected function after() {
        return false;
    }

    public function indexAction() {
        View::renderTemplate('Home/index.html', [
            'name' => 'Raphael',
            'colours' => ['red', 'green', 'blue']
        ]);
    }
}
```

#### Before and After Functions

- `before()`: Executes before page content is loaded (e.g., check if user is logged in).
- `after()`: Executes after page content has loaded (e.g., save user data to track time spent on a page).

Enable these functions by returning `true`, disable by returning `false`.

#### Defining Actions

URL structure: `http://localhost/dashboard/index`

- `$router->add('{controller}/{action}');`
  - `{controller}` = dashboard
  - `{action}` = index

Note: Add 'Action' to your PHP function names (e.g., `indexAction`, `usersAction`).

#### Loading HTML Content into a View

Use `View::renderTemplate($path, $options);`
- `$path`: path to the view (e.g., 'Home/index.html')
- `$options`: array of data to pass to the HTML

#### Using Passed Data in HTML

```html
{{ NAME_OF_ARRAY_ITEM }}
```

#### Adding a Model

1. Import the model in your controller:
   ```php
   use App\Models\Post;
   ```

2. Use the model in your controller action:
   ```php
   $users = Post::getAll();
   View::renderTemplate('Posts/index.html', ['users' => $users]);
   ```

3. Create a model file (e.g., Post.php) in the models folder:

```php
<?php
namespace App\Models;

use Core\Model;
use PDO;

class Post extends Model {
    public static function getAll(): bool|array {
        try {
            $db = static::getDB();
            $stmt = $db->query('SELECT * FROM members ORDER BY id');
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception($e->getMessage());
        }
    }
}
```

#### Using Model Data in HTML

```html
{% extends "base.html" %}
{% block title %} User lists {% endblock %}
{% block body %}
<h1>Users list</h1>
<p>Hello {{ name }} </p>
<ul>
{% for user in users %}
<li><h2>{{ user.name }}</h2> <p>{{ user.lastname }}</p></li>
{% endfor %}
</ul>
{% endblock %}
```

### 🤖 AI Integration (OpenAI & Claude AI)

First, set up your API keys in the Iris config file:

```php
const OPEN_AI_API_KEY = "YOUR KEY HERE";
const OPEN_AI_ENDPOINT = "https://api.openai.com/v1/";
const OPEN_AI_DEFAULT_MODEL = "gpt-3.5-turbo";

const CLAUDE_AI_API_KEY = "YOUR KEY HERE";
const CLAUD_AI_ENDPOINT = "https://api.anthropic.com/v1/messages";
```

#### OpenAI Functions

Available functions:
- Text response
- Image generation
- Transcription
- Translation
- Analysis
- Code generation

Usage examples:

```php
$textResponse = OpenAI::generateText("Tell me a joke about programming.");
$imageUrl = OpenAI::generateImage("A futuristic city with flying cars");
$transcription = OpenAI::transcribeAudio("/path/to/audio/file.mp3");
$translation = OpenAI::translateText("Hello, world!", "French");
$analysis = OpenAI::analyzeContent("Long text to be analyzed...");
$code = OpenAI::generateCode("Python", "Create a function that calculates the factorial of a number.");
```

#### Claude AI Functions

Available functions:
- Text response
- Translation
- Analysis
- Code generation

Usage examples:

```php
$response = ClaudAI::generateResponse("Tell me a joke about programming.");
$analysis = ClaudAI::analyzeText("Long text to be analyzed...");
$translation = ClaudAI::translateText("Hello, world!", "French");
$code = ClaudAI::generateCode("Python", "Create a function that calculates the factorial of a number.");
```

### 🔒 Security Functions

Enhanced Security class features:
- Secure random token generation
- Data encryption and decryption
- Modern password hashing and verification
- CSRF protection mechanisms
- Input sanitization to prevent XSS attacks
- Secure file naming
- Password strength checking

Usage examples:

```php
$token = Security::generateRandomToken();
$encryptedData = Security::encryptData('sensitive data', 'secret_key');
$decryptedData = Security::decryptData($encryptedData, 'secret_key');
$passwordHash = Security::hashPassword('user_password');
$isPasswordValid = Security::verifyPassword('user_password', $passwordHash);
$csrfToken = Security::generateCSRFToken();
$sanitizedInput = Security::sanitizeInput($_POST['user_input']);
$secureFilename = Security::generateSecureFileName('user_upload.jpg');
$isStrong = Security::isStrongPassword('User@Password123');
```

### Google API

example:
```php
$google = new Google('path/to/client_secret.json');

// For the first-time use, you'll need to authenticate:
$authUrl = $google->getAuthUrl(['https://www.googleapis.com/auth/drive.file', 'https://www.googleapis.com/auth/calendar']);
// Redirect the user to $authUrl, then exchange the received code for an access token:
$accessToken = $google->fetchAccessTokenWithAuthCode($authCode);
$google->setAccessToken($accessToken);

// Now you can use the API functions:
$location = $google->geocode('1600 Amphitheatre Parkway, Mountain View, CA');
$files = $google->listDriveFiles();
$events = $google->listCalendarEvents();
$sheetData = $google->readSheetData('spreadsheetId', 'Sheet1!A1:B10');
```


🎉 With these features and examples, you're ready to build powerful and secure web applications using the Iris Framework!