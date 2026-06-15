### Setup Instruction
- Clone project using `git clone https://github.com/sw0103771/razer-assesment.git`
- Run `composer install` 
- Rename `.env.example` to `.env`
- Create database named "star_media" or based on your database name set in ENV
- Run `php artisan migrate --seed` to migrate database and seed data
- Run `php artisan key:generate` to generate key if required (if needed)
- Run `php artisan serve` to run the backend system

- Flow :
    1. Serve `http://127.0.0.1:8000/tester.html` to test 