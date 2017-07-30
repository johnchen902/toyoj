from setuptools import setup, find_packages
setup(
    name="ToyojJudge",
    version="0.9",
    packages=find_packages(),
    install_requires=[
        "asyncpg >= 0.11.0",
        "ConfigArgParse >= 0.12.0",
        "Flask==0.12.2.dev0",
        "Markdown==2.6.8",
        "bleach==2.0.0",
        "psycopg2==2.7.2",
    ],

    entry_points= {
        'console_scripts': [
            'toyoj-judge = toyoj.judge.main:main'
        ]
    },

    author = "John Chen",
    author_email = "johnchen902@gmail.com",
    description = "Toy Online Judge judge backend",
    license = "AGPL",
    url = "https://github.com/johnchen902/toyoj",
)
