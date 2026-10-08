# fileShare
Quickly share files with others by sending them a link, using your own web server. People with the link can download the file.

<img width="636" height="693" alt="image" src="https://github.com/user-attachments/assets/bda56ec7-8a1d-481a-b56d-e54d6f33138f" />

To start using it, use Apache and rename "htaccess.txt" to ".htaccess" and "secrets.php.example" to "secrets.php". Edit both files to configure a public URL that will be part of the file links, a username for you, a password for you, and a secret token for your session so that you can log in and upload & delete files.

If you don't want to use Apache, set your web server environment variables to those that are named in the htaccess.txt file and configure them as described above.
