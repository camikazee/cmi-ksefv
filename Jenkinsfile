pipeline {
    agent any
    options {
        timestamps()
        disableConcurrentBuilds()
        buildDiscarder(logRotator(numToKeepStr: '20'))
    }
    stages {
        stage('Checkout') { steps { checkout scm } }
        stage('Package Gate') {
            steps {
                sh '''
                    set -eu
                    RUN_ID=$(printf '%s-%s' "${JOB_NAME:-local}" "${BUILD_NUMBER:-0}" | tr -c 'a-zA-Z0-9_.-' '-' | tr '[:upper:]' '[:lower:]')
                    IMAGE="cmi-ksefv-test:$RUN_ID"
                    NAME="cmi-ksefv-test-$RUN_ID"
                    trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true; docker rmi "$IMAGE" >/dev/null 2>&1 || true' EXIT
                    docker build --target test -t "$IMAGE" .
                    status=0
                    docker run --name "$NAME" "$IMAGE" || status=$?
                    mkdir -p .jenkins-results
                    docker cp "$NAME:/app/var/phpunit.junit.xml" .jenkins-results/ || true
                    exit "$status"
                '''
            }
            post {
                always { junit allowEmptyResults: true, testResults: '.jenkins-results/*.xml' }
            }
        }
    }
    post { always { cleanWs() } }
}
