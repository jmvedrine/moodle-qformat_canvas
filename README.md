#Canvas XML question import format

This Moodle question import format can import questions exported from the Canvas LMS as an XML file into Moodle.

It was created by Jean-Michel Vedrine.
##Installation
To install, either download the zip file, unzip it, and place it in the directory moodle\question\format. (You will need to rename the directory from "moodle-qformat_fronter" to just "fronter".)

Alternatively, get the code using git by running the following command in the top level folder of your Moodle install:

git clone git://github.com/jmvedrine/moodle-qformat_fronter.git question/format/fronter

You must visit Site administration and install it like any other Moodle plugin.

It will then add a new choice when you import questions in the question bank.
##Support
Please report bugs and improvements ideas using this forum thread:
https://moodle.org/mod/forum/discuss.php?d=269499

This work was made possible by comments, ideas and test files provided by Moodle users on the Moodle quiz forum:
https://moodle.org/mod/forum/view.php?id=737

Jean-Michel Védrine
vedrine@univ-st-etienne.fr