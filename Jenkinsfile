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