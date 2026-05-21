pipeline {
    options {
        skipDefaultCheckout(true)
    }

    agent any
    
    environment {
        DOCKER_CMD  = "sudo docker"
    }

    stages {

        stage('Checkout') {
            steps {
                cleanWs()
                checkout scm
            }
        }

        stage('Run Test + Coverage') {
            steps {
                sh '''
                ${DOCKER_CMD} run --rm \
                -v $PWD:/app \
                -w /app \
                php:8.4-cli \
                sh -c "
                    apt update &&
                    apt install -y git unzip libzip-dev curl libpng-dev libjpeg-dev libfreetype6-dev &&
                    docker-php-ext-configure gd --with-freetype --with-jpeg &&
                    docker-php-ext-install gd zip pcntl &&
                    pecl install xdebug &&
                    docker-php-ext-enable xdebug &&
                    curl -sS https://getcomposer.org/installer | php &&
                    mv composer.phar /usr/local/bin/composer &&
                    git config --global --add safe.directory /app &&
                    composer install &&
                    XDEBUG_MODE=coverage php artisan test --coverage-clover=coverage.xml
                "
                '''
            }
        }

        stage('SonarQube Analysis') {
            steps {
                withCredentials([string(credentialsId: 'sonarqube-token', variable: 'SONAR_TOKEN')]) {
                    sh '''
                    PROJECT_KEY=$(grep '^sonar.projectKey=' sonar-project.properties | cut -d'=' -f2-)

                    ${DOCKER_CMD} run --rm \
                        --network host \
                        -v $PWD:/usr/src \
                        sonarsource/sonar-scanner-cli \
                        -Dsonar.projectKey=$PROJECT_KEY \
                        -Dsonar.sources=app \
                        -Dsonar.host.url=http://127.0.0.1:9200/sonarcube \
                        -Dsonar.login=$SONAR_TOKEN \
                        -Dsonar.php.coverage.reportPaths=coverage.xml \
                        -Dsonar.qualitygate.wait=true
                    '''
                }
            }
        }

        // stage('Quality Gate') {
        //     steps {
        //         timeout(time: 2, unit: 'MINUTES') {
        //             waitForQualityGate abortPipeline: true
        //         }
        //     }
        // }

        stage('Deploy') {
            steps {
                sh '''
                cp ".env.demo" ".env"
                ${DOCKER_CMD} compose down
                ${DOCKER_CMD} compose up -d --build
                '''
            }
        }
    }
}