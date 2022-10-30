# PHP-Framework "Iris"
## _A simple fast and easy to use framework_

Iris is a PHP-Framework created to make it easy to build a website, without needing much knowledge or to learn a new PHP-Framework.

- Fast & Easy to set up
- Just implement your controllers, views and models
- Link pre-build function to your controllers

## Features

- Error logging system, local or in database.
- Easy to enable functions via config file.
- Build-in page router check wiki how to edit.
- Build-in hashing classes.
- Advanced error handler.
- Build-in PDO style database handler
- Build-in Twig view handler so u can use HTML to design your application layout.
- More to come..


## Wiki

### Views, controls & models 
Iris uses TWIG to handle views on your application. To use your own HTML.

To do so you need to edit the base.html file in the Views folder. These will be the base of your application.

What you need to include in your base.html

If you want to change the title of the window when navigating pages use following title tag in your HTML head.

    <title>{% block title %}{% endblock %}</title>

In the body tag u need to include the following code. I recommend to put it after navigation bar and before any js scripts.

    {% block body %}{% endblock %}
Once you have done this you are ready to create your web application. In order to create a view you need to create a folder named exactly to the website page.

for example, you have a 'Home' page you will need to create a 'Home' folder in the Views folder. In the just created folder you will need to add an index.html file and add the following code.

    {% extends "base.html" %}
    {% block title %} Dashboard {% endblock%}
    {% block body %}
    <p>I'm a html view</p>
    {% endblock%}

Between the block title you put the page title this will be used to report the title in de browser window.
Your HTML code needs to be set between the block body and endblock like in the example.

Now you need to create a 'Home.php' file in the Controller folder. And it will need to look like this.

    <?php
        namespace App\Controllers;

        use \Core\View;

        class Home extends \Core\Controller {

            protected function before(){
                return false;
            }
        
            protected function after(){
                return false;
            }
        
            public function indexAction (){
                View::renderTemplate('Home/index.html', [
                    'name' => 'Raphael',
                    'colours' => ['red', 'green', 'blue']
                ]);
            }
        }
# Before function

This function is set to do some back-end functions before the page content is loaded. For example: You check if the user is logged in or not.
To disable this function use 'return false;', and to enable it use 'return true;'.

# After function

This function is set to do some back-end functions after the page content has loaded. For example, saving user data into a database to track time spent on a page.
To disable this function use 'return false;', and to enable it use 'return true;'.

# Defining actions

Imagine this is the url of the loaded page : http://localhost/dashboard/index
We break the url out like this: 

- $router->add('{controller}/{action}');
  - {controller} = dashboard
  - {action} = index

Note: The {action} var is a prefix of a php function. In order to add the function in our PHP file we need to add 'Action' in our function name.
In the case of our example url: 

- {action} = index
  - PHP function = indexAction

or

- {action} = users
    - PHP function = usersAction
 
# How to load HTML content into a view.

In order to load the HTML view we have to call the function View::renderTemplate($path, $options);
$path = path to the view in our case 'Home/index.html'
$option are set as an array and will be pass through to use in our HTML code. You can pass it like in the code above. 

# How to use the passed data to HTML ?

In order to use the passed data we need to call it like this:

    {{ NAME_OF_ARRAY_ITEM }}

NAME_OF_ARRAY_ITEM = like in our example: 'name' and will output Raphael.

To add a model file you need to add the following

    use App\Models\$_NAME_OF_MODEL$;
    
Where $_NAME_OF_MODEL$ will be the name of the created model.
In the action function of the Controller u need to define the imported model.
For example:

    use App\Models\Post;


    $users = Post::getAll();

You can then pass the $users data via the option field of the View::renderTemplate in form of array like this:

    View::renderTemplate('Posts/index.html',
        ['users' => $users]);

Then you need to create a model in the models' folder. The model file needs to take the name of the import you used. For our example we will create a Post.php file.
This page will house your back-end functions for the actual page.
The model page will look like this

    <?php

    namespace App\Models;

    use Core\Model;
    use Core\Security;
    use PDO;

    class Post extends Model {

    public static function getAll(): bool|array
        {
            try {
                $db = static::getDB();
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt = $db->query('SELECT * FROM members ORDER BY id');
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e){
                throw new Exception($e->getMessage());
            }
        }
    }

# How to call a Model function

In order to call a model function you will need to import it into the associated controller file.
Example Posts.php controller:
Note: In our example the model returns an array.

    <?php
        namespace App\Controllers;

        use \Core\View;
        use App\Models\Post;                                                            ----> HERE WE IMPORT THE MODEL

        class Posts extends \Core\Controller {
        
            public function indexAction(){
                $users = Post::getAll();                                                ----> HERE WE DEFINE THE MODEL TO A VAR.
                View::renderTemplate('Posts/index.html',
                ['users' => $users]);                                                   ----> HERE WE PASS THE MODEL FUNCTION SO IT CAN BE CALLED IN OUR HTML
            }
        
            public function addNewAction(){
                echo "hello from the addNew action in the Posts controller";
            }
        
            public function editAction(){
        
            }
        }

# Example of using the model data in HTML.

    {% extends "base.html" %}
    {% block title %} User lists {% endblock%}
    {% block body %}
    <h1>Users list</h1>
    <p>Hello {{ name }} </p>
    <ul>
    {% for user in users %}
    <li><h2>{{ user.name }}</h2> <p>{{ user.lastname }}</p></li>
    {% endfor %}
    </ul>
    {% endblock%}

